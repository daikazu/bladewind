<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Analysis;

use Daikazu\BladeWind\Declarations\Safelist;

final class ClassIndex
{
    /**
     * @param  list<string>  $declared
     * @return array<string, IndexRow>
     */
    public function build(ClassAnalysis $analysis, array $declared, Safelist $safelist): array
    {
        /** @var array<string, array{static: int, enumerable: int, runtime: int, candidate: int}> $counts */
        $counts = [];

        $bump = static function (string $token, string $column) use (&$counts): void {
            $counts[$token] ??= ['static' => 0, 'enumerable' => 0, 'runtime' => 0, 'candidate' => 0];
            $counts[$token][$column]++;
        };

        foreach ($analysis->groups as $group) {
            foreach ($group->tokens as $token) {
                $bump($token, 'static');
            }

            foreach ($group->conditional as $conditional) {
                foreach ($conditional->tokens as $token) {
                    $bump($token, 'enumerable');
                }
            }
        }

        foreach ($analysis->runtime as $runtime) {
            foreach ($runtime->tokens as $token) {
                $bump($token, 'runtime');
            }
        }

        foreach ($analysis->candidates as $candidates) {
            foreach ($candidates->tokens as $token) {
                $bump($token, 'candidate');
            }
        }

        $declaredSet = array_fill_keys($declared, true);

        foreach ($declared as $token) {
            $counts[$token] ??= ['static' => 0, 'enumerable' => 0, 'runtime' => 0, 'candidate' => 0];
        }

        ksort($counts, SORT_STRING);

        $rows = [];

        foreach ($counts as $token => $count) {
            $rows[$token] = new IndexRow(
                $count['static'],
                $count['enumerable'],
                $count['runtime'],
                $count['candidate'],
                isset($declaredSet[$token]),
                $safelist->matches((string) $token),
            );
        }

        return $rows;
    }
}
