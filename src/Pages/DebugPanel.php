<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * The metrics panel `bladewind.debug` adds to every page: what the package did for this response
 * and what it saved.
 *
 * It carries no `class` attribute and no `<script>`, so it never joins a page's class set and works
 * under any CSP that allows the page's own styles: every rule lives in one `<style>` scoped to
 * `[data-bladewind-panel]`, carrying the Vite CSP nonce when one is registered.
 *
 * The collapsed summary is a ring draining from the full stylesheet's size to what this page
 * loaded, plus the sizes and the saving. The body lists the three stylesheets and how the page one
 * was produced; a "Check" row appears only when there is something to act on.
 */
final class DebugPanel
{
    public const MARKER = 'data-bladewind-panel';

    private const GREEN = '#34d399';

    private const AMBER = '#fbbf24';

    private const CSS = <<<'CSS'
@property --bw-ring{syntax:'<number>';inherits:false;initial-value:100}
[data-bladewind-panel]{position:fixed;right:12px;bottom:12px;z-index:2147483000;width:min(92vw,380px);font:12px/1.45 ui-sans-serif,system-ui,-apple-system,sans-serif;color:#e5e7eb;background:#111827;border:1px solid #2b3444;border-radius:12px;box-shadow:0 12px 32px -8px rgba(0,0,0,.5),0 2px 6px rgba(0,0,0,.25);text-align:left;font-variant-numeric:tabular-nums;-webkit-font-smoothing:antialiased;display:flex;flex-direction:column;max-height:calc(100dvh - 24px)}
[data-bladewind-panel] ::selection{background:#34d399;color:#052e16}
[data-bladewind-panel] summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:12px;color:#fff;transition:background .15s}
[data-bladewind-panel] summary::-webkit-details-marker{display:none}
[data-bladewind-panel] summary:hover{background:#161e2e}
[data-bladewind-panel] summary:focus-visible{outline:2px solid #34d399;outline-offset:-2px}
[data-bladewind-panel][open] summary{border-radius:12px 12px 0 0}
[data-bladewind-panel] [data-bw=ring]{position:relative;flex:none;width:26px;height:26px;border-radius:50%;background:conic-gradient(var(--bw-tone,#34d399) calc(var(--bw-ring)*1%),#2b3444 0);animation:bw-ring-in 1.2s cubic-bezier(.16,1,.3,1) .15s backwards}
@keyframes bw-ring-in{from{--bw-ring:100}}
[data-bladewind-panel] [data-bw=ring]::after{content:"";position:absolute;inset:4px;border-radius:50%;background:#111827}
[data-bladewind-panel] [data-bw=brand]{font-weight:700;letter-spacing:.01em}
[data-bladewind-panel] [data-bw=size]{margin-left:auto;display:flex;align-items:baseline;gap:5px;white-space:nowrap}
[data-bladewind-panel] [data-bw=size] b{font-size:15px;font-weight:600;letter-spacing:-.01em}
[data-bladewind-panel] [data-bw=size] small{font-size:11px;color:#8b95a7}
[data-bladewind-panel] [data-bw=delta]{flex:none;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:600;color:var(--bw-tone,#34d399);background:color-mix(in srgb,var(--bw-tone,#34d399) 14%,transparent)}
[data-bladewind-panel] [data-bw=body]{padding:0 12px 10px;border-top:1px solid #2b3444;overflow:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#3a4556 transparent}
[data-bladewind-panel] summary{flex:none}
[data-bladewind-panel][open] [data-bw=body]{animation:bw-panel-in .4s cubic-bezier(.16,1,.3,1)}
@keyframes bw-panel-in{from{opacity:0;translate:0 4px}}
[data-bladewind-panel] [data-bw=section]{margin:11px 0 5px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8b95a7}
[data-bladewind-panel] table{border-collapse:collapse;width:100%}
[data-bladewind-panel] td{padding:5px 0;vertical-align:top}
[data-bladewind-panel] tr+tr>td{border-top:1px solid rgba(255,255,255,.055)}
[data-bladewind-panel] [data-bw=label]{padding-right:10px;color:#8b95a7;white-space:nowrap;width:1%}
[data-bladewind-panel] [data-bw=value]{color:#f9fafb}
[data-bladewind-panel] [data-bw=hint]{display:block;color:#6b7280;font-size:11px;text-wrap:pretty}
[data-bladewind-panel] [data-bw=bar]{display:flex;height:6px;margin:8px 0 3px;border-radius:3px;overflow:hidden;background:#2b3444}
[data-bladewind-panel] [data-bw=bar]>span{height:100%;clip-path:inset(0 0 0 0);transition:clip-path .9s cubic-bezier(.16,1,.3,1) .15s}
[data-bladewind-panel] [data-bw=bar]>span:first-child{background:#60a5fa}
[data-bladewind-panel] [data-bw=bar]>span:last-child{background:#34d399}
[data-bladewind-panel] [data-bw=legend] i[data-bw=root]{background:#60a5fa}
[data-bladewind-panel] [data-bw=legend] i[data-bw=page]{background:#34d399}
[data-bladewind-panel] [data-bw=legend] i[data-bw=rest]{background:#2b3444}
[data-bladewind-panel] [data-bw=legend]{display:flex;gap:12px;margin:0 0 2px;color:#8b95a7;font-size:11px}
[data-bladewind-panel] [data-bw=legend] i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:4px;vertical-align:-1px}
CSS;

    /**
     * Emitted after the per-response rules: a `@starting-style` declaration only wins when it comes
     * later in the sheet than the value it starts from. The ring animates through keyframes instead,
     * since Chrome does not start a registered custom property from `@starting-style`.
     */
    private const CSS_TAIL = <<<'CSS'
@starting-style{[data-bladewind-panel] [data-bw=bar]>span{clip-path:inset(0 100% 0 0)}}
@media (prefers-reduced-motion:reduce){[data-bladewind-panel] *{transition:none!important;animation:none!important}}
CSS;

    /**
     * @param  array{}|array{fallback: string}|array{set: string, tokens: int, analysed: int, unanalysed: int, support: string, delivery: string, framework?: string, framework_detected?: bool, cached: bool, root_file: string, page_file: string, root_bytes: int, root_gzip: int, page_bytes: int, page_gzip: int, full_bytes: int, full_gzip: int|null, rendered?: int, unanalysed_kinds?: array{strings: int, outside: list<string>, outside_count: int, outside_more?: bool}}  $metrics
     * @param  string  $nonceAttribute  ` nonce="..."` when the application registered a CSP nonce with Vite, else empty
     */
    public static function render(array $metrics, float $milliseconds, string $nonceAttribute = ''): string
    {
        if ($metrics === []) {
            return '';
        }

        if (isset($metrics['fallback'])) {
            [$reason, $next] = self::fallback($metrics['fallback']);

            return self::open(
                $nonceAttribute,
                '[data-bladewind-panel] [data-bw=ring]{--bw-ring:100;--bw-tone:'.self::AMBER.'}',
                '<span data-bw="size"><small>full stylesheet served instead</small></span>',
            )
                .self::section('Why')
                .self::table([
                    self::row('Reason', $reason, $next, 'Why page styles were skipped for this response'),
                    self::row('Time', self::ms($milliseconds), 'Added to this response by BladeWind before falling back', 'Time BladeWind spent on this response'),
                ])
                .self::close();
        }

        $fullGzip = $metrics['full_gzip'];
        $delivered = $metrics['root_gzip'] + $metrics['page_gzip'];
        $values = '';
        $summary = '<span data-bw="size"><b>'.e(self::kb($delivered)).'</b><small>CSS</small></span>';

        if ($fullGzip !== null && $fullGzip > 0) {
            $loaded = $delivered / $fullGzip * 100;
            $saved = (int) round(100 - $loaded);
            $tone = $saved >= 0 ? self::GREEN : self::AMBER;
            $values .= '[data-bladewind-panel] [data-bw=ring]{--bw-ring:'.number_format(min(100, $loaded), 1, '.', '').';--bw-tone:'.$tone.'}[data-bladewind-panel] [data-bw=delta]{--bw-tone:'.$tone.'}';
            $summary = '<span data-bw="size"><b>'.e(self::kb($delivered)).'</b><small>of '.e(self::kb($fullGzip)).'</small></span>'
                .'<span data-bw="delta">'.($saved >= 0 ? '−' : '+').abs($saved).'%</span>';
        }

        $bar = '';

        if ($fullGzip !== null && $fullGzip > 0) {
            [$barCss, $bar] = self::bar($metrics['root_gzip'], $metrics['page_gzip'], $fullGzip);
            $values .= $barCss;
        }

        $html = self::open($nonceAttribute, $values, $summary);

        $html .= self::section('Stylesheets, gzipped')
            .self::table([
                self::row('Shared root', self::bytes($metrics['root_bytes'], $metrics['root_gzip']), '', 'Loaded by every page: base styles, non-utility CSS, keyframes, and their variables'),
                self::row('This page', self::bytes($metrics['page_bytes'], $metrics['page_gzip']), '', 'Generated for this page: the utilities it can use and the variables they read'),
                self::row('Full stylesheet', self::bytes($metrics['full_bytes'], $fullGzip), '', 'Your compiled Vite stylesheet, for comparison. Not loaded on this page'),
            ])
            .$bar;

        $rows = [
            self::row(
                'Page stylesheet',
                ($metrics['cached'] ? 'reused from disk' : 'generated for this request').' · '.self::ms($milliseconds),
                '',
                ($metrics['cached'] ? 'An earlier request with the same classes already wrote it. ' : 'First request with this set of classes; the next one reuses it. ').'The time is what BladeWind added to this response, not counting the work only this panel and the debug header need',
            ),
            self::row(
                'Delivery',
                $metrics['delivery'] === 'inline' ? 'inline <style> element' : '<link> to a cached file',
                '',
                $metrics['delivery'] === 'inline'
                    ? 'The page CSS is embedded in the HTML, so wire:navigate never paints unstyled (pages.delivery = inline)'
                    : 'The browser fetches the page stylesheet once and caches it (pages.delivery = link)',
            ),
        ];

        if (isset($metrics['framework'])) {
            $rows[] = self::row(
                'Framework',
                self::framework($metrics['framework']),
                '',
                ($metrics['framework_detected'] ?? true)
                    ? 'Recognised from the compiled stylesheet, and how it was split into root and page (bladewind.framework = auto)'
                    : 'Named in configuration, and how the stylesheet was split into root and page (bladewind.framework = '.$metrics['framework'].')',
            );
        }

        // Only what the developer can act on earns a row here: the counts behind the class set are
        // in the X-BladeWind-Styles header for anyone who wants them. Views rendered from strings
        // (`Blade::render()`, inline SVG helpers) have no source to add anywhere, so they never do.
        $kinds = $metrics['unanalysed_kinds'] ?? ['strings' => 0, 'outside' => [], 'outside_count' => 0, 'outside_more' => false];
        $rendered = $metrics['rendered'] ?? ($metrics['analysed'] + $metrics['unanalysed']);

        if ($kinds['outside_count'] > 0) {
            $rows[] = self::row(
                'Check',
                $kinds['outside_count'].' of '.$rendered.' rendered views outside paths',
                'Only the classes already in their HTML are covered, not ones they add later. Add to bladewind.paths: '.implode(', ', $kinds['outside']).(($kinds['outside_more'] ?? false) ? ', …' : ''),
                'Rendered views BladeWind could not analyse because no configured path contains them',
            );
        }

        if (! is_numeric($metrics['support'])) {
            $rows[] = self::row(
                'Check',
                'theme kept whole in the root',
                'Your theme layer is in a shape BladeWind cannot split yet, so every page pays for every variable. BW6005 in the log names the shape',
                'The theme layer could not be split per page',
            );
        }

        return $html
            .self::section('How this page was built')
            .self::table($rows)
            .self::close();
    }

    /**
     * A driver's name in plain words; an application-registered driver is shown by its name.
     */
    private static function framework(string $name): string
    {
        return match ($name) {
            'tailwind4' => 'Tailwind CSS 4',
            'tailwind3' => 'Tailwind CSS 3',
            'tachyons' => 'Tachyons',
            'bootstrap5' => 'Bootstrap 5',
            'bulma' => 'Bulma',
            'foundation' => 'Foundation for Sites',
            default => $name,
        };
    }

    /**
     * The fallback reason in plain words, and what to do about it. Unknown reasons (an exception
     * message) are shown as they are.
     *
     * @return array{0: string, 1: string}
     */
    private static function fallback(string $reason): array
    {
        return match ($reason) {
            'disabled' => ['page styles are turned off', 'pages.enabled is false, so every page gets the full stylesheet'],
            'vite hot' => ['Vite dev server is running', 'The stylesheet is served from memory, not from disk, so there is nothing to split. Stop the dev server or run a build to see page styles'],
            'nothing to split' => ['nothing in the stylesheet to split', 'The compiled stylesheet has none of what its CSS framework driver looks for, so there is nothing to split per page. BW6002 in the log says what was expected'],
            'stylesheet not recognised' => ['compiled stylesheet not recognised', 'No CSS framework driver recognised the compiled stylesheet, so there is nothing to split per page. Name one in bladewind.framework, or see BW6002 in the log for what was looked for'],
            'stylesheet not found through the Vite manifest' => ['compiled stylesheet not found', 'No configured stylesheet resolves through public/build/manifest.json. Run vite build'],
            'rendered views have no analysis entry' => ['a rendered view could not be analysed', 'A view rendered from outside bladewind.paths (a package view) has no analysis entry, so its hidden branches are unknown. Add its directory to paths; BW6003 in the log names it'],
            'page stylesheet could not be written' => ['page stylesheet could not be written', 'Check that the web server can write to the generated-files directory under public'],
            default => [$reason, 'The full stylesheet was linked exactly as @vite would. The log has the details under BW6001'],
        };
    }

    /**
     * The `<details>` shell: the scoped stylesheet (static rules plus this response's $values), then
     * the summary with the ring, the brand and $summary's figures.
     */
    private static function open(string $nonceAttribute, string $values, string $summary): string
    {
        return '<details '.self::MARKER.'>'
            .'<style'.$nonceAttribute.'>'.self::CSS.$values.self::CSS_TAIL.'</style>'
            .'<summary><span data-bw="ring"></span><span data-bw="brand">BladeWind</span>'.$summary.'</summary>'
            .'<div data-bw="body">';
    }

    private static function close(): string
    {
        return '</div></details>';
    }

    private static function section(string $title): string
    {
        return '<div data-bw="section">'.e($title).'</div>';
    }

    /**
     * @param  list<string>  $rows
     */
    private static function table(array $rows): string
    {
        return '<table>'.implode('', $rows).'</table>';
    }

    private static function row(string $label, string $value, string $hint, string $tooltip): string
    {
        return '<tr><td data-bw="label" title="'.e($tooltip).'">'.e($label).'</td>'
            .'<td data-bw="value">'.e($value).($hint === '' ? '' : '<span data-bw="hint">'.e($hint).'</span>').'</td></tr>';
    }

    /**
     * Root and page as shares of the full stylesheet's gzipped size; the grey remainder is what
     * was not loaded. Returns the per-response width rules and the markup separately, so the widths
     * join the scoped stylesheet; each segment is revealed with a `clip-path` sweep from
     * `@starting-style`, which paints without layout work.
     *
     * @return array{0: string, 1: string} [css, html]
     */
    private static function bar(int $rootGzip, int $pageGzip, int $fullGzip): array
    {
        $root = min(100, $rootGzip / $fullGzip * 100);
        $page = min(100 - $root, $pageGzip / $fullGzip * 100);

        $css = '[data-bladewind-panel] [data-bw=bar]>span:first-child{width:'.number_format($root, 1, '.', '').'%}'
            .'[data-bladewind-panel] [data-bw=bar]>span:last-child{width:'.number_format($page, 1, '.', '').'%}';

        $html = '<div data-bw="bar"><span></span><span></span></div>'
            .'<div data-bw="legend">'
            .'<span><i data-bw="root"></i>shared root</span>'
            .'<span><i data-bw="page"></i>this page</span>'
            .'<span><i data-bw="rest"></i>not loaded</span>'
            .'</div>';

        return [$css, $html];
    }

    private static function bytes(int $raw, ?int $gzip): string
    {
        return $gzip === null ? self::kb($raw).' raw' : self::kb($gzip).' ('.self::kb($raw).' raw)';
    }

    private static function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 1).' KB';
    }

    private static function ms(float $milliseconds): string
    {
        return number_format($milliseconds, 1).' ms';
    }
}
