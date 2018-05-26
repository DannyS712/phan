<?php declare(strict_types=1);
namespace Phan\Plugin\Internal;

use Phan\Exception\CodeBaseException;
use Phan\Issue;
use Phan\PluginV2\PostAnalyzeNodeCapability;
use Phan\PluginV2\PluginAwarePostAnalysisVisitor;
use Phan\PluginV2;
use Phan\Plugin\Internal\VariableTracker\VariableGraph;
use Phan\Plugin\Internal\VariableTracker\VariableTrackingScope;
use Phan\Plugin\Internal\VariableTracker\VariableTrackerVisitor;
use ast\Node;
use ast;

/**
 * NOTE: This is automatically loaded by phan based on config settings.
 * Do not include it in the 'plugins' config.
 */
final class VariableTrackerPlugin extends PluginV2 implements
    PostAnalyzeNodeCapability
{

    /**
     * @return string the name of a visitor
     */
    public static function getPostAnalyzeNodeVisitorClassName() : string
    {
        return VariableTrackerElementVisitor::class;
    }
}

/**
 * TODO: Hook into the global scope as well?
 */
final class VariableTrackerElementVisitor extends PluginAwarePostAnalysisVisitor
{
    public function visitMethod(Node $node)
    {
        $this->analyzeMethodLike($node);
    }

    /**
     * @override
     */
    public function visitFuncDecl(Node $node)
    {
        $this->analyzeMethodLike($node);
    }

    /**
     * @override
     */
    public function visitClosure(Node $node)
    {
        $this->analyzeMethodLike($node);
    }

    /**
     * @return void
     */
    private function analyzeMethodLike(Node $node)
    {
        // \Phan\Debug::printNode($node);
        $stmts_node = $node->children['stmts'] ?? null;
        if (!($stmts_node instanceof Node)) {
            return;
        }
        $variable_graph = new VariableGraph();
        $scope = new VariableTrackingScope();
        $issue_categories = $this->addParametersAndUseVariablesToGraph($node, $variable_graph, $scope);

        try {
            VariableTrackerVisitor::$variable_graph = $variable_graph;
            $variable_tracker_visitor = new VariableTrackerVisitor($scope);
            // TODO: Add params and use variables.
            $variable_tracker_visitor->__invoke($stmts_node);
        } finally {
            // @phan-suppress-next-line PhanTypeMismatchProperty
            VariableTrackerVisitor::$variable_graph = null;
        }
        $this->warnAboutVariableGraph($node, $variable_graph, $issue_categories);
    }

    /**
     * @return array<int,string> maps unique definition ids to issue types
     */
    private function addParametersAndUseVariablesToGraph(
        Node $node,
        VariableGraph $graph,
        VariableTrackingScope $scope
    ) : array {
        $result = [];
        // AST_PARAM_LIST of AST_PARAM
        foreach ($node->children['params']->children as $parameter) {
            \assert($parameter instanceof Node);
            $parameter_name = $parameter->children['name'];
            if (!is_string($parameter_name)) {
                continue;
            }
            // We narrow this down to the specific category if we need to warn.
            $result[\spl_object_id($parameter)] = Issue::UnusedPublicMethodParameter;

            $graph->recordVariableDefinition($parameter_name, $parameter, $scope);
            if ($parameter->flags & ast\flags\PARAM_REF) {
                $graph->markAsReference($parameter_name);
            }
        }
        foreach ($node->children['uses']->children ?? [] as $closure_use) {
            \assert($closure_use instanceof Node);
            $name = $closure_use->children['name'];
            if (!is_string($name)) {
                continue;
            }
            $result[\spl_object_id($closure_use)] = Issue::UnusedClosureUseVariable;

            $graph->recordVariableDefinition($parameter_name, $closure_use, $scope);
            if ($closure_use->flags & ast\flags\PARAM_REF) {
                $graph->markAsReference($name);
            }
        }
        return $result;
    }

    private function getParameterCategory(Node $method_node) {
        $kind = $method_node->kind;
        if ($kind === ast\AST_CLOSURE) {
            return Issue::UnusedClosureParameter;
        } elseif ($kind === ast\AST_FUNC_DECL) {
            return Issue::UnusedGlobalFunctionParameter;
        }

        $flags = $method_node->flags;
        $final = $this->isParameterFinal($flags);

        if ($flags & ast\flags\MODIFIER_PRIVATE) {
            return $final ? Issue::UnusedPrivateFinalMethodParameter : Issue::UnusedPrivateMethodParameter;
        } elseif ($flags & ast\flags\MODIFIER_PROTECTED) {
            return $final ? Issue::UnusedProtectedFinalMethodParameter : Issue::UnusedProtectedMethodParameter;
        }
        return $final ? Issue::UnusedPublicFinalMethodParameter : Issue::UnusedPublicMethodParameter;
    }

    private function isParameterFinal(int $flags) : bool {
        if (($flags & ast\flags\MODIFIER_FINAL) !== 0) {
            return true;
        }
        $context = $this->context;
        if ($context->isInClassScope()) {
            try {
                $class = $context->getClassInScope($this->code_base);
                return $class->isFinal();
            } catch (CodeBaseException $e) {
            }
        }
        return false;
    }

    /**
     * @param array<int,string> $issue_overrides_for_definition_ids maps definition ids to issue types
     * @return void
     */
    private function warnAboutVariableGraph(
        Node $node,
        VariableGraph $graph,
        $issue_overrides_for_definition_ids
    ) {
        $result = [];
        foreach ($graph->def_uses as $variable_name => $def_uses_for_variable) {
            if (preg_match('/^(_$|(unused|raii))/i', $variable_name) > 0) {
                // Skip over $_, $unused*, and $raii*
                continue;
            }
            $type_bitmask = $graph->variable_types[$variable_name] ?? 0;
            if ($type_bitmask > 0) {
                // don't warn about static/global/references
                continue;
            }
            foreach ($def_uses_for_variable as $definition_id => $use_list) {
                if (\count($use_list) > 0) {
                    // Don't warn if there's at least one usage of that definition
                    continue;
                }
                $line = $graph->def_lines[$variable_name][$definition_id] ?? 1;
                $issue_type = $issue_overrides_for_definition_ids[$definition_id] ?? Issue::UnusedVariable;
                if ($issue_type === Issue::UnusedPublicMethodParameter) {
                    // Narrow down issues about parameters into more specific issues
                    $issue_type = $this->getParameterCategory($node);
                }
                Issue::maybeEmit(
                    $this->code_base,
                    $this->context,
                    $issue_type,
                    $line,
                    $variable_name
                );
            }
        }
    }
}
