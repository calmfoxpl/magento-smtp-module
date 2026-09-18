<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests;

use Calmfox\Smtp\Core\Diagnosis\Cause;
use Calmfox\Smtp\Core\Diagnosis\Hint;
use Calmfox\Smtp\Core\Health\HealthState;
use Calmfox\Smtp\Core\Settings\IssueCode;
use PHPUnit\Framework\TestCase;

/**
 * Magento assembles a module out of XML, and a mistake there only shows on a live installation
 * after `setup:upgrade`: a page answers 404 on a permission that does not exist, a block cannot
 * find its template, a cron job names a class nobody wrote. None of that is caught by a unit
 * test of the core, and all of it is caught by reading the files against each other.
 *
 * Three of these tests earn their place beyond that, though:
 *
 *  - the health record has to fit the table it is stored in, column for column, or the module
 *    silently forgets half of what it knows the first time it restarts,
 *  - every cause, hint and settings complaint the core can produce has to have a sentence
 *    waiting for it, because a code with no sentence reaches an administrator as a blank box,
 *  - every sentence has to exist in both languages, with its placeholders intact. A missing
 *    translation is not an error anywhere; it just quietly shows English to somebody who does
 *    not read it.
 */
final class WiringTest extends TestCase
{
    private const NAMESPACE_PREFIX = 'Calmfox\\Smtp\\';

    private const MODULE = 'Calmfox_Smtp';

    /**
     * Strings that read the same in Polish as in English: the names of mechanisms and places,
     * two words Polish has borrowed whole, and a unit.
     */
    private const SAME_IN_BOTH = [
        '%1 ms',
        'Australia',
        'DKIM',
        'DMARC',
        'SPF',
        'CRAM-MD5',
        'E-mail',
        'Frankfurt',
        'LOGIN',
        'Log',
        'Oregon',
        'PLAIN',
        'Port',
        'STARTTLS',
    ];

    // ── the files telling the truth about each other ──────────────────────────

    /** Every class named in the XML has to exist under this module. */
    public function testEveryClassNamedInXmlExists(): void
    {
        $found = 0;

        foreach (self::xmlFiles() as $file) {
            $content = self::read($file);
            preg_match_all('/<virtualType name="([^"]+)"/', $content, $virtual);
            preg_match_all('/Calmfox\\\\Smtp\\\\[A-Za-z0-9_\\\\]+/', $content, $matches);

            foreach (array_unique($matches[0]) as $class) {
                if (\in_array($class, $virtual[1], true)) {
                    continue;
                }
                ++$found;
                self::assertFileExists(self::pathFor($class), sprintf('%s names %s, which has no file', $file, $class));
            }
        }

        self::assertGreaterThan(10, $found);
    }

    /** Every template a block declares is shipped with the module. */
    public function testEveryDeclaredTemplateExists(): void
    {
        $declared = 0;

        foreach (self::filesIn(\dirname(__DIR__) . '/Block', ['php']) as $block) {
            if (!preg_match('/' . self::MODULE . '::([A-Za-z0-9_\/.\-]+\.phtml)/', self::contents($block), $match)) {
                continue;
            }
            ++$declared;
            self::assertFileExists(
                \dirname(__DIR__) . '/view/adminhtml/templates/' . $match[1],
                sprintf('%s declares %s, which is not in the module', basename($block), $match[1]),
            );
        }

        self::assertGreaterThan(2, $declared);
    }

    /** Every browser module a template asks for is shipped too. */
    public function testEveryRequiredBrowserModuleExists(): void
    {
        $required = [];

        foreach (self::filesIn(\dirname(__DIR__) . '/view', ['phtml']) as $template) {
            preg_match_all("#'" . self::MODULE . "/js/([A-Za-z0-9_\-]+)'#", self::contents($template), $matches);
            foreach ($matches[1] as $module) {
                $required[$module] = basename($template);
            }
        }

        self::assertNotEmpty($required);
        foreach ($required as $module => $template) {
            self::assertFileExists(
                \dirname(__DIR__) . '/view/adminhtml/web/js/' . $module . '.js',
                sprintf('%s requires %s.js, which is not in the module', $template, $module),
            );
        }
    }

    /** Every stylesheet a layout asks for is shipped too. */
    public function testEveryDeclaredStylesheetExists(): void
    {
        foreach (self::filesIn(\dirname(__DIR__) . '/view/adminhtml/layout', ['xml']) as $layout) {
            preg_match_all('/<css src="' . self::MODULE . '::([^"]+)"/', self::contents($layout), $matches);
            foreach ($matches[1] as $stylesheet) {
                self::assertFileExists(\dirname(__DIR__) . '/view/adminhtml/web/' . $stylesheet, basename($layout));
            }
        }
    }

    /** The route front name is what every URL in this module is built from. */
    public function testTheRouteFrontNameIsStable(): void
    {
        $route = self::xml('etc/adminhtml/routes.xml')->router->route;

        self::assertSame('calmfox_smtp', (string) $route['id']);
        self::assertSame('calmfox_smtp', (string) $route['frontName']);
    }

    /** A controller demanding a permission nobody can be granted is a 404 with extra steps. */
    public function testEveryPermissionNamedAnywhereExistsInAcl(): void
    {
        $acl = self::read('etc/acl.xml');
        $named = [];

        foreach (self::filesIn(\dirname(__DIR__) . '/Controller', ['php']) as $controller) {
            preg_match_all('/ADMIN_RESOURCE = \'([^\']+)\'/', self::contents($controller), $matches);
            foreach ($matches[1] as $resource) {
                $named[$resource] = basename($controller);
            }
        }
        foreach (['etc/adminhtml/menu.xml', 'etc/adminhtml/system.xml', 'view/adminhtml/ui_component/calmfox_smtp_log_listing.xml'] as $file) {
            preg_match_all('/' . self::MODULE . '::[A-Za-z0-9_]+/', self::read($file), $matches);
            foreach ($matches[0] as $resource) {
                $named[$resource] = $file;
            }
        }

        self::assertNotEmpty($named);
        foreach ($named as $resource => $where) {
            self::assertStringContainsString(
                sprintf('resource id="%s"', $resource),
                $acl,
                sprintf('%s names the permission %s, which is not in acl.xml', $where, $resource),
            );
        }
    }

    /** Every menu entry points at an action of this module and a permission it declares. */
    public function testTheMenuPointsAtItsOwnPages(): void
    {
        foreach (self::xml('etc/adminhtml/menu.xml')->menu->add as $item) {
            $action = (string) $item['action'];
            if ('' === $action) {
                continue;
            }
            [$route, $controller, $action] = array_pad(explode('/', $action), 3, 'index');
            self::assertSame('calmfox_smtp', $route);
            self::assertFileExists(
                sprintf('%s/Controller/Adminhtml/%s/%s.php', \dirname(__DIR__), ucfirst($controller), ucfirst($action)),
                sprintf('the menu points at %s/%s, which has no controller', $controller, $action),
            );
        }
    }

    /** Every cron job names a class that exists, and a schedule it can actually read. */
    public function testTheCronJobsAreWiredToSomething(): void
    {
        $jobs = self::xml('etc/crontab.xml')->group->job;
        self::assertCount(3, $jobs);

        foreach ($jobs as $job) {
            self::assertFileExists(self::pathFor((string) $job['instance']));
            self::assertSame('execute', (string) $job['method']);

            $configPath = (string) ($job->config_path ?? '');
            if ('' === $configPath) {
                self::assertNotSame('', (string) ($job->schedule ?? ''), (string) $job['name']);
                continue;
            }
            self::assertSame('calmfox_smtp', explode('/', $configPath)[0]);
            self::assertTrue(
                self::hasDefaultFor($configPath),
                sprintf('%s is scheduled by %s, which has no default', (string) $job['name'], $configPath),
            );
        }
    }

    /** The grid's data source has to be the one di.xml gives a collection to. */
    public function testTheGridIsWiredToItsCollection(): void
    {
        preg_match('/<dataSource name="([^"]+)"/', self::read('view/adminhtml/ui_component/calmfox_smtp_log_listing.xml'), $listing);

        self::assertStringContainsString(
            sprintf('<item name="%s" xsi:type="string">', $listing[1]),
            self::read('etc/di.xml'),
        );
    }

    /** The transport is the whole point of the module; nothing else would call it. */
    public function testTheTransportIsInFrontOfMagentos(): void
    {
        self::assertStringContainsString(
            '<preference for="Magento\Framework\Mail\TransportInterface" type="Calmfox\Smtp\Model\Mail\Transport"/>',
            self::read('etc/di.xml'),
        );
    }

    /**
     * Magento's own "send no e-mail" setting has to be honoured by whatever stands in front of
     * its transport. Without this, installing the module would start a staging copy sending
     * real e-mail to real customers.
     */
    public function testMagentosOwnOffSwitchIsHonoured(): void
    {
        $transport = self::read('Model/Mail/Transport.php');

        self::assertStringContainsString('sendingIsSwitchedOffInMagento', $transport);
        self::assertStringContainsString("\$magento['disable']", $transport);
    }

    /** The core asks DNS through an interface, so something has to be bound to it. */
    public function testTheDomainChecksAreWiredToAResolver(): void
    {
        self::assertStringContainsString(
            '<preference for="Calmfox\Smtp\Core\Dns\Resolver" type="Calmfox\Smtp\Model\Dns\PhpResolver"/>',
            self::read('etc/di.xml'),
        );
    }

    /**
     * Two bars in the panel, in two colours. "Cannot send" is critical and wants somebody within
     * the hour; "the domain will cost you delivered mail" is a warning for whoever runs the DNS.
     * One colour for both would train an administrator to ignore the pair.
     */
    public function testTheTwoPanelMessagesSpeakInDifferentColours(): void
    {
        $di = self::read('etc/di.xml');

        self::assertStringContainsString('Calmfox\Smtp\Model\Notification\SystemMessage', $di);
        self::assertStringContainsString('Calmfox\Smtp\Model\Notification\DeliverabilityMessage', $di);

        self::assertStringContainsString('self::SEVERITY_CRITICAL', self::read('Model/Notification/SystemMessage.php'));
        self::assertStringContainsString('self::SEVERITY_MAJOR', self::read('Model/Notification/DeliverabilityMessage.php'));
    }

    /**
     * Nothing that renders may ask DNS anything.
     *
     * The system resolver takes no timeout it can be told about, so a page that looked up a
     * record would hang for as long as somebody else's nameserver felt like. The lookups belong
     * to the button, the cron job and the console; everything else reads what they stored. This
     * is the kind of rule that is obeyed for a fortnight and then quietly broken by a one-line
     * convenience, so it is a test.
     */
    public function testNoPageAsksDnsAnythingWhileItRenders(): void
    {
        $renderers = array_merge(
            self::filesIn(\dirname(__DIR__) . '/Block', ['php']),
            self::filesIn(\dirname(__DIR__) . '/Model/Notification', ['php']),
            self::filesIn(\dirname(__DIR__) . '/view', ['phtml']),
        );
        self::assertNotEmpty($renderers);

        foreach ($renderers as $file) {
            self::assertStringNotContainsString(
                '->refresh(',
                self::contents($file),
                sprintf('%s would make a DNS lookup while rendering a page', basename($file)),
            );
        }
    }

    // ── the record fitting the table it lives in ──────────────────────────────

    public function testTheHealthRecordFitsItsTable(): void
    {
        $columns = self::columnsOf('calmfox_smtp_health');
        $fields = array_keys((new HealthState())->toArray());

        foreach ($fields as $field) {
            self::assertContains($field, $columns, sprintf('the health record has %s, the table does not', $field));
        }
        foreach (array_diff($columns, $fields, ['entity_id', 'store_id']) as $orphan) {
            self::fail(sprintf('the table has a column nobody writes: %s', $orphan));
        }
    }

    /** Declarative schema refuses to add anything that is not also in the whitelist. */
    public function testTheSchemaWhitelistIsComplete(): void
    {
        $whitelist = json_decode(self::read('etc/db_schema_whitelist.json'), true);
        self::assertIsArray($whitelist);

        foreach (self::xml('etc/db_schema.xml')->table as $table) {
            $name = (string) $table['name'];
            self::assertArrayHasKey($name, $whitelist);

            foreach ($table->column as $column) {
                self::assertArrayHasKey(
                    (string) $column['name'],
                    $whitelist[$name]['column'],
                    sprintf('%s.%s is not whitelisted, so it would never be created', $name, (string) $column['name']),
                );
            }
        }
    }

    // ── every code having a sentence ──────────────────────────────────────────

    public function testEveryCauseHasAShortNameAndASentence(): void
    {
        $wording = self::read('Model/Text/Wording.php');
        [$labels, $details] = self::twoHalvesOf($wording, 'public function cause', 'public function hint');

        foreach (Cause::ALL as $cause) {
            $constant = self::constantNameFor(Cause::class, $cause);
            self::assertStringContainsString('Cause::' . $constant . ' =>', $labels, sprintf('%s has no short name', $cause));
            self::assertStringContainsString('Cause::' . $constant . ' =>', $details, sprintf('%s has no sentence', $cause));
        }
    }

    public function testEveryHintHasASentence(): void
    {
        $wording = self::read('Model/Text/Wording.php');

        foreach (Hint::ALL as $hint) {
            self::assertStringContainsString(
                'Hint::' . self::constantNameFor(Hint::class, $hint) . ' =>',
                $wording,
                sprintf('the hint %s would reach an administrator as an empty box', $hint),
            );
        }
    }

    public function testEverySettingsComplaintHasASentence(): void
    {
        $wording = self::read('Model/Text/Wording.php');

        foreach (IssueCode::ALL as $code) {
            self::assertStringContainsString(
                'IssueCode::' . self::constantNameFor(IssueCode::class, $code),
                $wording,
                sprintf('the settings complaint %s has no sentence', $code),
            );
        }
    }

    /** Every setting the module reads has a default, or a fresh install reads an empty string. */
    public function testEveryConfiguredPathHasADefault(): void
    {
        preg_match_all("/'([a-z_]+)\\/([a-z_]+)'/", self::read('Model/Config.php'), $matches, \PREG_SET_ORDER);
        $checked = 0;

        foreach ($matches as [, $group, $field]) {
            if (\in_array($group, ['trans_email', 'ident_general', 'general', 'store_information', 'system'], true)) {
                continue; // Magento's own settings, not ours to default
            }
            ++$checked;
            self::assertTrue(
                self::hasDefaultFor(sprintf('calmfox_smtp/%s/%s', $group, $field)),
                sprintf('calmfox_smtp/%s/%s is read but has no default', $group, $field),
            );
        }

        self::assertGreaterThan(10, $checked);
    }

    // ── the two languages ─────────────────────────────────────────────────────

    /**
     * Every translatable string the module produces has to be in both translation files.
     *
     * The strings come from four places that are easy to forget one of: the code, the templates,
     * the configuration screen (which translates comments as well as labels) and the grid.
     */
    public function testEveryTranslatableStringIsInBothTranslationFiles(): void
    {
        $used = self::translatableStrings();
        self::assertGreaterThan(100, \count($used));

        foreach (['en_US', 'pl_PL'] as $locale) {
            $translated = self::translations($locale);

            foreach ($used as $string) {
                self::assertArrayHasKey(
                    $string,
                    $translated,
                    sprintf('%s.csv has no entry for "%s"', $locale, mb_substr($string, 0, 70)),
                );
            }
        }
    }

    /**
     * The provider hints are translated through the catalogue rather than written as literals,
     * so the extractor above cannot see them. They are the sentences a shopkeeper reads while
     * typing a credential, which makes them the last ones that should be left in English.
     */
    public function testEveryProviderHintIsInBothTranslationFiles(): void
    {
        $strings = [];
        foreach (\Calmfox\Smtp\Core\Provider\ProviderCatalog::all() as $provider) {
            foreach ($provider->hints() as $hint) {
                $strings[] = $hint;
            }
            foreach (array_keys($provider->hostVariants) as $region) {
                $strings[] = (string) $region;
            }
        }

        self::assertNotEmpty($strings);
        foreach (['en_US', 'pl_PL'] as $locale) {
            $translated = self::translations($locale);
            foreach (array_unique($strings) as $string) {
                self::assertArrayHasKey($string, $translated, sprintf('%s.csv has no entry for "%s"', $locale, mb_substr($string, 0, 70)));
            }
        }
    }

    /** The Polish file must actually translate, not repeat the English. */
    public function testThePolishFileIsTranslated(): void
    {
        $untranslated = [];
        foreach (self::translations('pl_PL') as $source => $target) {
            if ($source === $target && !\in_array($source, self::SAME_IN_BOTH, true)) {
                $untranslated[] = mb_substr($source, 0, 60);
            }
        }

        self::assertSame([], $untranslated);
    }

    /** A placeholder dropped in translation shows the reader a literal %1. */
    public function testPlaceholdersSurviveTranslation(): void
    {
        foreach (self::translations('pl_PL') as $source => $target) {
            preg_match_all('/%\d+/', $source, $inSource);
            preg_match_all('/%\d+/', $target, $inTarget);

            sort($inSource[0]);
            sort($inTarget[0]);
            self::assertSame($inSource[0], $inTarget[0], sprintf('placeholders differ for "%s"', mb_substr($source, 0, 50)));
        }
    }

    /** Neither file may carry an entry for a string nothing says any more. */
    public function testTheTranslationFilesHaveNothingSpare(): void
    {
        $used = array_merge(self::translatableStrings(), self::providerStrings());

        foreach (['en_US', 'pl_PL'] as $locale) {
            $spare = array_diff(array_keys(self::translations($locale)), $used);
            self::assertSame([], array_values($spare), sprintf('%s.csv has entries nothing uses', $locale));
        }
    }

    // ── plumbing ──────────────────────────────────────────────────────────────

    /** @return list<string> every string the module asks to be translated */
    private static function translatableStrings(): array
    {
        $strings = [];

        foreach (self::filesIn(\dirname(__DIR__), ['php', 'phtml']) as $file) {
            if (str_contains($file, '/tests/')) {
                continue;
            }
            $content = self::contents($file);
            preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $content, $single);
            preg_match_all('/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $content, $double);
            foreach ($single[1] as $match) {
                $strings[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $match);
            }
            foreach ($double[1] as $match) {
                $strings[] = str_replace(['\\"', '\\\\'], ['"', '\\'], $match);
            }
        }

        // The browser modules speak as well, and Magento translates them out of these same
        // files by way of js-translation.json.
        foreach (self::filesIn(\dirname(__DIR__) . '/view/adminhtml/web/js', ['js']) as $file) {
            preg_match_all('/\$\.mage\.__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', self::contents($file), $matches);
            foreach ($matches[1] as $match) {
                $strings[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $match);
            }
        }

        foreach (self::xmlFiles() as $file) {
            $strings = array_merge($strings, self::translatablesIn(\dirname(__DIR__) . '/' . $file));
        }

        return array_values(array_unique(array_filter(array_map(static fn (string $string): string => trim($string), $strings))));
    }

    /**
     * Magento translates XML in three shapes: an element named by @translate, an attribute
     * named by it, and — in a UI component — the element's own text under translate="true".
     *
     * @return list<string>
     */
    private static function translatablesIn(string $path): array
    {
        $document = new \DOMDocument();
        $document->load($path);
        $xpath = new \DOMXPath($document);
        $strings = [];

        foreach ($xpath->query('//*[@translate]') ?: [] as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $tokens = preg_split('/\s+/', trim($element->getAttribute('translate'))) ?: [];

            foreach ($tokens as $token) {
                if ('true' === $token) {
                    $strings[] = self::oneLine($element->textContent);
                    continue;
                }
                if ($element->hasAttribute($token)) {
                    $strings[] = self::oneLine($element->getAttribute($token));
                    continue;
                }
                foreach ($element->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->nodeName === $token) {
                        $strings[] = self::oneLine($child->textContent);
                    }
                }
            }
        }

        return $strings;
    }

    /** @return list<string> */
    private static function providerStrings(): array
    {
        $strings = [];
        foreach (\Calmfox\Smtp\Core\Provider\ProviderCatalog::all() as $provider) {
            foreach ($provider->hints() as $hint) {
                $strings[] = $hint;
            }
            foreach (array_keys($provider->hostVariants) as $region) {
                $strings[] = (string) $region;
            }
        }

        return $strings;
    }

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /** @return array<string, string> */
    private static function translations(string $locale): array
    {
        $path = \dirname(__DIR__) . '/i18n/' . $locale . '.csv';
        self::assertFileExists($path);

        $rows = [];
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        while (false !== ($row = fgetcsv($handle, 0, ',', '"', '\\'))) {
            if (\is_array($row) && 2 === \count($row) && \is_string($row[0]) && \is_string($row[1])) {
                $rows[$row[0]] = $row[1];
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<string> */
    private static function columnsOf(string $table): array
    {
        $columns = [];
        foreach (self::xml('etc/db_schema.xml')->table as $candidate) {
            if ((string) $candidate['name'] !== $table) {
                continue;
            }
            foreach ($candidate->column as $column) {
                $columns[] = (string) $column['name'];
            }
        }

        self::assertNotEmpty($columns, sprintf('%s is not in the schema', $table));

        return $columns;
    }

    private static function hasDefaultFor(string $path): bool
    {
        [, $group, $field] = explode('/', $path);
        $defaults = self::xml('etc/config.xml')->default->calmfox_smtp;

        return isset($defaults->{$group}->{$field});
    }

    /** @return array{0: string, 1: string} */
    private static function twoHalvesOf(string $content, string $first, string $second): array
    {
        $start = strpos($content, $first);
        $middle = strpos($content, $second);
        self::assertIsInt($start);
        self::assertIsInt($middle);

        $detailStart = strpos($content, 'public function detail');
        self::assertIsInt($detailStart);

        return [substr($content, $start, $detailStart - $start), substr($content, $detailStart, $middle - $detailStart)];
    }

    private static function constantNameFor(string $class, string $value): string
    {
        foreach ((new \ReflectionClass($class))->getConstants() as $name => $constant) {
            if ($constant === $value) {
                return $name;
            }
        }

        self::fail(sprintf('%s has no constant for "%s"', $class, $value));
    }

    /** @return list<string> */
    private static function xmlFiles(): array
    {
        $files = [];
        foreach (['etc', 'view/adminhtml/layout', 'view/adminhtml/ui_component'] as $directory) {
            foreach (self::filesIn(\dirname(__DIR__) . '/' . $directory, ['xml']) as $file) {
                $files[] = ltrim(str_replace(\dirname(__DIR__), '', $file), '/');
            }
        }
        sort($files);

        return $files;
    }

    private static function pathFor(string $class): string
    {
        return \dirname(__DIR__) . '/' . str_replace('\\', '/', str_replace(self::NAMESPACE_PREFIX, '', $class)) . '.php';
    }

    private static function xml(string $relative): \SimpleXMLElement
    {
        $path = \dirname(__DIR__) . '/' . $relative;
        self::assertFileExists($path);
        $xml = simplexml_load_file($path);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, sprintf('%s is not valid XML', $relative));

        return $xml;
    }

    private static function read(string $relative): string
    {
        $path = \dirname(__DIR__) . '/' . $relative;
        self::assertFileExists($path);

        return self::contents($path);
    }

    private static function contents(string $path): string
    {
        return (string) file_get_contents($path);
    }

    /**
     * @param list<string> $extensions
     *
     * @return list<string>
     */
    private static function filesIn(string $directory, array $extensions): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && \in_array($file->getExtension(), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
