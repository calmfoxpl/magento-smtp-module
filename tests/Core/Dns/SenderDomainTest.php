<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Core\Dns;

use Calmfox\Smtp\Core\Dns\SenderDomain;
use Calmfox\Smtp\Tests\Support\Settings;
use PHPUnit\Framework\TestCase;

final class SenderDomainTest extends TestCase
{
    /**
     * The domain that will be judged is the one the shop sends *as*, not the one it connects to.
     * Checking the provider's own domain would always come back clean and mean nothing.
     */
    public function testTheForcedSenderWinsBecauseEveryMessageWillCarryIt(): void
    {
        $settings = Settings::make(username: 'apikey', host: 'smtp.sendgrid.net', fromEmail: 'zamowienia@sklep.pl');

        self::assertSame('sklep.pl', SenderDomain::of($settings));
    }

    public function testTheLoginIsTheLastResort(): void
    {
        self::assertSame('example.com', SenderDomain::of(Settings::make(username: 'shop@example.com')));
    }

    public function testALoginThatIsNotAnAddressTellsUsNothing(): void
    {
        self::assertNull(SenderDomain::of(Settings::make(username: 'apikey')));
        self::assertNull(SenderDomain::of(Settings::make(username: '')));
    }

    public function testItIsReadAsADomainOrNotAtAll(): void
    {
        self::assertSame('sklep.pl', SenderDomain::domainOf('Anna <anna@Sklep.PL>'));
        self::assertSame('sklep.pl', SenderDomain::domainOf('anna@sklep.pl.'));
        self::assertNull(SenderDomain::domainOf('anna@localhost'));
        self::assertNull(SenderDomain::domainOf('anna@10.0.0.1]'));
        self::assertNull(SenderDomain::domainOf('nie-adres'));
        self::assertNull(SenderDomain::domainOf('anna@-sklep.pl'));
    }
}
