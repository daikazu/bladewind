<?php

declare(strict_types=1);

use Daikazu\BladeWind\Manifest\ManifestStore;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

it('inspects by component name, view name, and path', function (): void {
    $this->artisan('bladewind:inspect', ['target' => 'chip'])
        ->expectsOutputToContain('components.chip')
        ->expectsOutputToContain('wire:loading.class')
        ->expectsOutputToContain('attributes-class')
        ->expectsOutputToContain('Page tokens')
        ->assertSuccessful();

    $this->artisan('bladewind:inspect', ['target' => 'pages.classes'])
        ->expectsOutputToContain('dynamic-component')
        ->expectsOutputToContain('BW2001')
        ->assertSuccessful();

    $this->artisan('bladewind:inspect', ['target' => $this->fixturePath('views/components/dropdown.blade.php')])
        ->expectsOutputToContain('themeClasses[current]')
        ->assertSuccessful();
});

it('shows dependents once other views have been analysed', function (): void {
    $this->artisan('bladewind:analyze')->assertSuccessful();

    $this->artisan('bladewind:inspect', ['target' => 'chip'])
        ->expectsOutputToContain('pages/classes.blade.php')
        ->assertSuccessful();
});

it('hints that dependents appear once other views are analysed when there are none yet', function (): void {
    $this->artisan('bladewind:inspect', ['target' => 'chip'])
        ->expectsOutputToContain('dependents appear once')
        ->assertSuccessful();
});

it('fails for class components, unknown targets, and files outside the configured paths', function (): void {
    $this->artisan('bladewind:inspect', ['target' => 'alert'])->expectsOutputToContain('App\View\Components\Alert')->assertExitCode(1);
    $this->artisan('bladewind:inspect', ['target' => 'does.not.exist'])->assertExitCode(1);

    $outside = sys_get_temp_dir().'/bw-inspect-'.bin2hex(random_bytes(3)).'.blade.php';
    file_put_contents($outside, '<p></p>');

    try {
        $this->artisan('bladewind:inspect', ['target' => $outside])->expectsOutputToContain('configured paths')->assertExitCode(1);
    } finally {
        unlink($outside);
    }
});

it('re-analyses a stale entry on demand and prints json', function (): void {
    $scratch = $this->fixturePath('views/scratch-inspect-'.bin2hex(random_bytes(3)).'.blade.php');
    file_put_contents($scratch, '<p class="one">1</p>');
    $name = basename($scratch, '.blade.php');

    try {
        expect(Artisan::call('bladewind:inspect', ['target' => $name, '--json' => true]))->toBe(0);
        $first = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        file_put_contents($scratch, '<p class="two">2</p>');

        expect(Artisan::call('bladewind:inspect', ['target' => $name, '--json' => true]))->toBe(0);
        $second = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        expect(array_keys($first))->toBe(['entry', 'graph'])
            ->and(array_keys($first['graph']))->toBe(['dependencies', 'dependents', 'transitive_dependencies', 'transitive_dependents'])
            ->and($first['entry']['classes']['groups'][0]['tokens'])->toBe(['one'])
            ->and($second['entry']['classes']['groups'][0]['tokens'])->toBe(['two'])
            ->and(app(ManifestStore::class)->get($scratch)?->classes->groups[0]->tokens)->toBe(['two']);
    } finally {
        unlink($scratch);
    }
});

it('writes the JSON inspection raw so console formatters never scan it', function (): void {
    $spy = new class extends BufferedOutput
    {
        /** @var list<int> */
        public array $types = [];

        public function write(string|iterable $messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void
        {
            $this->types[] = $options & (self::OUTPUT_NORMAL | self::OUTPUT_RAW | self::OUTPUT_PLAIN);
            parent::write($messages, $newline, $options);
        }
    };

    expect(Artisan::call('bladewind:inspect', ['target' => 'chip', '--json' => true], $spy))->toBe(0)
        ->and($spy->types)->toBe([OutputInterface::OUTPUT_RAW])
        ->and(trim($spy->fetch()))->toStartWith('{');
});
