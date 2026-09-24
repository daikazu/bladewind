<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Analysis;

use Daikazu\BladeWind\Declarations\ComponentDeclarations;
use Daikazu\BladeWind\Declarations\Safelist;
use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Discovery\SourceFile;
use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Manifest\ClassesBlock;
use Daikazu\BladeWind\Manifest\Fingerprint;
use Daikazu\BladeWind\Manifest\ViewEntry;
use Daikazu\BladeWind\Parsing\SourceParser;
use Throwable;

class ViewAnalyzer
{
    public function __construct(
        private SourceParser $parser,
        private DependencyExtractor $extractor,
        private CompatHash $compat,
        private ClassAnalyzer $classes,
        private ClassIndex $index,
        private ComponentDeclarations $declarations,
        private Safelist $safelist,
    ) {}

    public function analyze(SourceFile $file, string $source): ViewEntry
    {
        $fingerprint = Fingerprint::fromSource($source, $file->path);
        $diagnostics = [];
        $dependencies = [];
        $classes = ClassesBlock::empty();

        try {
            $template = $this->parser->parse($source);

            foreach ($template->parseErrors as $error) {
                $diagnostics[] = Codes::make(Codes::ANALYSIS_FAILED, ['message' => $error]);
            }

            $extraction = $this->extractor->extract($template, $this->declarations->dynamicTargetsFor($file->path));
            $dependencies = $extraction->dependencies;
            $diagnostics = [...$diagnostics, ...$extraction->diagnostics];

            $analysis = $this->classes->analyze($template);
            $classes = new ClassesBlock(
                $analysis->groups,
                $analysis->runtime,
                $analysis->candidates,
                $analysis->unresolved,
                $this->index->build($analysis, $this->declarations->classesFor($file->path), $this->safelist),
            );
            $diagnostics = [...$diagnostics, ...$analysis->diagnostics];
        } catch (Throwable $exception) {
            $dependencies = [];
            $classes = ClassesBlock::empty();
            $diagnostics[] = Codes::make(Codes::ANALYSIS_FAILED, ['message' => $exception->getMessage()]);
        }

        return new ViewEntry(
            path: $file->path,
            relativePath: $file->relativePath,
            name: $file->name,
            kind: $file->kind,
            fingerprint: $fingerprint,
            compatHash: $this->compat->current(),
            dependencies: $dependencies,
            classes: $classes,
            diagnostics: $diagnostics,
        );
    }
}
