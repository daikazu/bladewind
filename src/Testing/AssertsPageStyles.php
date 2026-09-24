<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Testing;

use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Pages\PageStyleStore;
use Daikazu\BladeWind\Pages\RenderedViews;
use Daikazu\BladeWind\Pages\StylesDirective;
use Daikazu\BladeWind\Stylesheets\CssEscape;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * One assertion for a host application's test suite: a page requested with page styles on was
 * served every rule it can need, and produced exactly the diagnostics its author expects.
 *
 * Mix into a test case that has `$this->get()` (Laravel's `Tests\TestCase`, Testbench's). The
 * request runs with `bladewind.debug` on so the response carries the `X-BladeWind-Styles`
 * header the checks are read from. Only the first configured stylesheet is split, so the
 * coverage check is scoped to it; secondary stylesheets are linked whole and their tokens are
 * not checked.
 *
 * @method TestResponse<\Illuminate\Http\Response> get(string $uri, array<string, string> $headers = [])
 */
trait AssertsPageStyles
{
    /**
     * @param  string  $uri  the page to request
     * @param  PageExpectation|null  $expect  what to check beyond coverage; null is `new PageExpectation`
     * @return PageStylesResult its `header` array names the leading `page=` field `set` and the trailing one `page` (the byte count)
     */
    protected function assertPageStyles(string $uri, ?PageExpectation $expect = null): PageStylesResult
    {
        $expect ??= new PageExpectation;

        config()->set('bladewind.debug', true);
        app(RenderedViews::class)->reset();

        /** @var list<string> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            if (preg_match_all('~BW\d{4}~', $event->message, $matches) > 0) {
                $logged = [...$logged, ...$matches[0]];
            }
        });

        $response = $this->get($uri);
        $response->assertOk();

        Assert::assertStringContainsString('text/html', (string) $response->headers->get('content-type'), sprintf('[%s] is not an HTML response.', $uri));

        $html = (string) $response->getContent();

        Assert::assertStringNotContainsString(StylesDirective::PLACEHOLDER, $html, sprintf('[%s] still carries the @bladewindStyles placeholder: the middleware did not run.', $uri));

        $header = (string) $response->headers->get('X-BladeWind-Styles');

        Assert::assertNotSame('', $header, sprintf('[%s] carries no X-BladeWind-Styles header. Is @bladewindStyles in the layout, and is bladewind.enabled on?', $uri));

        $rendered = app(RenderedViews::class)->paths();
        $diagnostics = $this->pageStylesDiagnostics($rendered, $logged);
        $fullCss = (string) (array_values(app(StylesheetIndex::class)->contents())[0] ?? '');

        Assert::assertNotSame('', $fullCss, sprintf('[%s] resolved no compiled stylesheet: run the build and check bladewind.stylesheets.', $uri));

        if (str_starts_with($header, 'fallback=')) {
            Assert::assertTrue($expect->fallback, sprintf('[%s] fell back to the full stylesheet: %s', $uri, $header));

            return new PageStylesResult(
                uri: $uri,
                html: $html,
                header: ['fallback' => substr($header, strlen('fallback='))],
                fallback: true,
                rootCss: '',
                pageCss: '',
                fullCss: $fullCss,
                rootFile: '',
                pageFile: '',
                delivery: 'fallback',
                diagnostics: $diagnostics,
            );
        }

        Assert::assertFalse($expect->fallback, sprintf('[%s] was expected to fall back to the full stylesheet but served page styles: %s', $uri, $header));

        $fields = $this->pageStylesHeader($uri, $header);

        if ($expect->unanalysed !== null) {
            Assert::assertSame((string) $expect->unanalysed, $fields['unanalysed'], sprintf('[%s] header reports %s unanalysed rendered views, expected %d: %s', $uri, $fields['unanalysed'], $expect->unanalysed, $header));
        }

        if ($expect->framework !== null) {
            Assert::assertSame($expect->framework, $fields['framework'], sprintf('[%s] was split by the %s driver, expected %s.', $uri, $fields['framework'], $expect->framework));
        }

        [$rootFile, $rootCss, $pageFile, $pageCss] = $this->pageStylesFiles($uri, $html, $fields);
        $served = $rootCss."\n".$pageCss;

        foreach ($this->pageStylesHtmlTokens($html) as $token) {
            if ($this->pageStylesHasRule($fullCss, $token)) {
                Assert::assertTrue($this->pageStylesHasRule($served, $token), sprintf('[%s] renders class [%s], which the full stylesheet styles, but neither %s nor %s has a rule for it.', $uri, $token, $rootFile, $pageFile));
            }
        }

        foreach ($expect->reaches as $token) {
            Assert::assertTrue($this->pageStylesHasRule($fullCss, $token), sprintf('[%s] expects to reach class [%s], which is not in the full stylesheet: the fixture is wrong, or the build is stale.', $uri, $token));
            Assert::assertTrue($this->pageStylesHasRule($served, $token), sprintf('[%s] can reach class [%s] at runtime but neither %s nor %s has a rule for it.', $uri, $token, $rootFile, $pageFile));
        }

        foreach ($expect->absent as $token) {
            Assert::assertFalse($this->pageStylesHasRule($pageCss, $token), sprintf('[%s] must not carry class [%s] but %s has a rule for it.', $uri, $token, $pageFile));
        }

        Assert::assertSame($expect->diagnostics, $diagnostics, sprintf('[%s] produced diagnostics [%s], expected [%s].', $uri, implode(', ', $diagnostics), implode(', ', $expect->diagnostics)));

        return new PageStylesResult(
            uri: $uri,
            html: $html,
            header: $fields,
            fallback: false,
            rootCss: $rootCss,
            pageCss: $pageCss,
            fullCss: $fullCss,
            rootFile: $rootFile,
            pageFile: $pageFile,
            delivery: $fields['delivery'],
            diagnostics: $diagnostics,
        );
    }

    /**
     * @return array<string, string>
     */
    private function pageStylesHeader(string $uri, string $header): array
    {
        $pattern = '~^page=(?<set>[0-9a-f]{12}) framework=(?<framework>\S+) tokens=(?<tokens>\d+) analysed=(?<analysed>\d+) unanalysed=(?<unanalysed>\d+) support=(?<support>\S+) delivery=(?<delivery>link|inline) root=(?<root>\d+) page=(?<page>\d+)$~';

        Assert::assertSame(1, preg_match($pattern, $header, $matches), sprintf('[%s] carries an X-BladeWind-Styles header this trait does not recognise: %s', $uri, $header));

        /** @var array<string, string> $fields */
        $fields = array_filter($matches, static fn (string|int $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{0: string, 1: string, 2: string, 3: string} root file, root CSS, page file, page CSS
     */
    private function pageStylesFiles(string $uri, string $html, array $fields): array
    {
        $directory = app(PageStyleStore::class)->directory();

        Assert::assertSame(1, preg_match('~bw-root-[0-9a-f]{12}\.css~', $html, $root), sprintf('[%s] links no root stylesheet.', $uri));
        $rootFile = $root[0];
        $rootCss = $this->pageStylesRead($uri, $directory.'/'.$rootFile);

        if ($fields['delivery'] === 'inline') {
            Assert::assertSame(1, preg_match('~<style data-bladewind-page="'.$fields['set'].'"[^>]*>(.*?)</style>~s', $html, $inline), sprintf('[%s] reports inline delivery but carries no <style data-bladewind-page> element.', $uri));

            $sheet = substr($rootFile, strlen('bw-root-'), -strlen('.css'));
            $pageFile = sprintf('bw-page-%s-%s.css', $sheet, $fields['set']);
            $this->pageStylesRead($uri, $directory.'/'.$pageFile);

            return [$rootFile, $rootCss, $pageFile, $inline[1]];
        }

        Assert::assertSame(1, preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $html, $page), sprintf('[%s] links no page stylesheet.', $uri));

        return [$rootFile, $rootCss, $page[0], $this->pageStylesRead($uri, $directory.'/'.$page[0])];
    }

    private function pageStylesRead(string $uri, string $path): string
    {
        Assert::assertFileExists($path, sprintf('[%s] links %s but the file was not written.', $uri, basename($path)));

        return (string) file_get_contents($path);
    }

    /**
     * The class tokens the served HTML carries, read independently of the package's own scanner:
     * every `class` attribute, after HTML comments, `<style>` blocks and every `<script>` block
     * that is not a template (`text/template`, `text/x-template`) are removed, entities decoded.
     *
     * @return list<string>
     */
    private function pageStylesHtmlTokens(string $html): array
    {
        $html = (string) preg_replace('~<!--.*?-->~s', '', $html);
        $html = (string) preg_replace('~<script\b(?![^>]*(?<![-\w])type\s*=\s*["\']?text/(?:x-)?template)[^>]*>.*?</script>~is', '', $html);
        $html = (string) preg_replace('~<style\b[^>]*>.*?</style>~is', '', $html);

        preg_match_all('~\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $html, $matches);

        /** @var array<string, true> $tokens */
        $tokens = [];

        foreach ([...$matches[1], ...$matches[2], ...$matches[3]] as $value) {
            foreach (preg_split('~\s+~', html_entity_decode($value, ENT_QUOTES | ENT_HTML5), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * Whether $css has a rule selecting class $token: a `.` followed by the escaped token and then
     * a character that cannot continue an identifier (so `.p-4` does not match `.p-40`, and
     * `.flex` does not match `.flex-1`).
     */
    private function pageStylesHasRule(string $css, string $token): bool
    {
        return preg_match('~\.'.preg_quote(CssEscape::escape($token), '~').'(?![\w-]|\\\\|[\x{80}-\x{10FFFF}])~u', $css) === 1;
    }

    /**
     * The BW codes on the rendered views' analysis entries plus the ones logged during the request,
     * sorted and unique.
     *
     * @param  list<string>  $rendered
     * @param  list<string>  $logged
     * @return list<string>
     */
    private function pageStylesDiagnostics(array $rendered, array $logged): array
    {
        $manifest = app(ManifestStore::class);
        $codes = $logged;

        foreach ($rendered as $path) {
            foreach ($manifest->get($path)?->diagnostics ?? [] as $diagnostic) {
                $codes[] = $diagnostic->code;
            }
        }

        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }
}
