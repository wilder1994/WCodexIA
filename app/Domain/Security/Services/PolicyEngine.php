<?php

declare(strict_types=1);

namespace App\Domain\Security\Services;

use App\Domain\Security\Contracts\PolicyRule;
use App\Domain\Security\Data\PolicyDecision;
use App\Domain\Security\Data\PolicySubject;
use App\Domain\Security\Enums\PolicyAction;

final readonly class PolicyEngine
{
    /**
     * @param  list<PolicyRule>  $inbound
     * @param  list<PolicyRule>  $outbound
     */
    public function __construct(
        private array $inbound,
        private array $outbound,
    ) {}

    public function inspectInbound(PolicySubject $subject): PolicyDecision
    {
        return $this->run($this->inbound, $subject);
    }

    public function inspectOutbound(PolicySubject $subject): PolicyDecision
    {
        return $this->run($this->outbound, $subject);
    }

    /**
     * @param  list<PolicyRule>  $rules
     */
    private function run(array $rules, PolicySubject $subject): PolicyDecision
    {
        $content = $subject->content;
        $verdicts = [];
        $redacted = false;

        foreach ($rules as $rule) {
            $verdict = $rule->evaluate(new PolicySubject(
                $content,
                $subject->providerIsLocal,
                $subject->task,
            ));
            $verdicts[] = $verdict;

            if ($verdict->action === PolicyAction::Deny) {
                return new PolicyDecision(
                    PolicyAction::Deny,
                    $verdicts,
                    $content,
                    $verdict->rule,
                );
            }

            if ($verdict->action === PolicyAction::Redact) {
                $content = $verdict->content;
                $redacted = true;
            }
        }

        return new PolicyDecision(
            $redacted ? PolicyAction::Redact : PolicyAction::Allow,
            $verdicts,
            $content,
        );
    }
}
