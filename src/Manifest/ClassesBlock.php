<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Manifest;

use Daikazu\BladeWind\Analysis\IndexRow;
use Daikazu\BladeWind\Classes\CandidateSet;
use Daikazu\BladeWind\Classes\ClassGroup;
use Daikazu\BladeWind\Classes\RuntimeClasses;
use Daikazu\BladeWind\Classes\UnresolvedConstruct;

final readonly class ClassesBlock
{
    /**
     * @param  list<ClassGroup>  $groups
     * @param  list<RuntimeClasses>  $runtime
     * @param  list<CandidateSet>  $candidates
     * @param  list<UnresolvedConstruct>  $unresolved
     * @param  array<string, IndexRow>  $index
     */
    public function __construct(
        public array $groups,
        public array $runtime,
        public array $candidates,
        public array $unresolved,
        public array $index,
    ) {}

    public static function empty(): self
    {
        return new self([], [], [], [], []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $index = [];

        foreach ($this->index as $token => $row) {
            $index[$token] = $row->toArray();
        }

        return [
            'groups' => array_map(static fn (ClassGroup $g): array => $g->toArray(), $this->groups),
            'runtime' => array_map(static fn (RuntimeClasses $r): array => $r->toArray(), $this->runtime),
            'candidates' => array_map(static fn (CandidateSet $c): array => $c->toArray(), $this->candidates),
            'unresolved' => array_map(static fn (UnresolvedConstruct $u): array => $u->toArray(), $this->unresolved),
            'index' => $index === [] ? new \stdClass : $index,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{groups: list<array{on: string, tag: string, source: string, classification: string, tokens: list<string>, conditional: list<array{tokens: list<string>, condition: string}>, unresolved: list<string>, bag: bool, line: int, column: int}>, runtime: list<array{adapter: string, attribute: string, classification: string, remove: bool, tokens: list<string>, conditional: list<array{tokens: list<string>, condition: string}>, unresolved: list<string>, line: int, column: int}>, candidates: list<array{origin: string, tokens: list<string>, line: int, column: int}>, unresolved: list<array{kind: string, expression: string, line: int, column: int}>, index: array<string, array{static: int, enumerable: int, runtime: int, candidate: int, declared: bool, safelisted: bool}>} $data */
        $index = [];

        foreach ($data['index'] as $token => $row) {
            $index[(string) $token] = IndexRow::fromArray($row);
        }

        return new self(
            array_map(static fn (array $g): ClassGroup => ClassGroup::fromArray($g), $data['groups']),
            array_map(static fn (array $r): RuntimeClasses => RuntimeClasses::fromArray($r), $data['runtime']),
            array_map(static fn (array $c): CandidateSet => CandidateSet::fromArray($c), $data['candidates']),
            array_map(static fn (array $u): UnresolvedConstruct => UnresolvedConstruct::fromArray($u), $data['unresolved']),
            $index,
        );
    }
}
