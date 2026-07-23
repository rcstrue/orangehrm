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

namespace OrangeHRM\Tests\Core\Security;

use OrangeHRM\Config\Config;
use OrangeHRM\Tests\Util\TestCase;

/**
 * @group Core
 * @group Security
 */
class HtaccessSecurityTest extends TestCase
{
    private string $htaccessContents;

    protected function setUp(): void
    {
        $htaccessPath = Config::get(Config::BASE_DIR) . '/.htaccess';
        $this->assertFileExists($htaccessPath, 'Document-root .htaccess is missing');
        $this->htaccessContents = file_get_contents($htaccessPath);
    }

    public function testYamlFilesAreDenied(): void
    {
        $this->assertTrue(
            $this->isDeniedByHtaccess('cli_install_config.yaml'),
            'installer/cli_install_config.yaml must be blocked by the root .htaccess'
        );
        $this->assertTrue(
            $this->isDeniedByHtaccess('anything.yaml'),
            '.yaml files must be blocked by the root .htaccess'
        );
    }

    public function testYmlFilesRemainDenied(): void
    {
        $this->assertTrue(
            $this->isDeniedByHtaccess('config.yml'),
            '.yml files must remain blocked by the root .htaccess'
        );
    }

    public function testRegularFilesAreNotDenied(): void
    {
        $this->assertFalse(
            $this->isDeniedByHtaccess('index.php'),
            'index.php must remain web-accessible'
        );
    }

    private function isDeniedByHtaccess(string $fileName): bool
    {
        if (preg_match_all('#<Files\s+([^>~]+?)\s*>(.*?)</Files>#is', $this->htaccessContents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($this->blockDenies($match[2]) && fnmatch(trim($match[1]), $fileName)) {
                    return true;
                }
            }
        }

        if (preg_match_all('#<FilesMatch\s+"(.*?)"\s*>(.*?)</FilesMatch>#is', $this->htaccessContents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($this->blockDenies($match[2]) && preg_match('#' . $match[1] . '#', $fileName)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function blockDenies(string $blockBody): bool
    {
        foreach (explode("\n", $blockBody) as $line) {
            $line = trim($line);
            if ($line === '' || (isset($line[0]) && $line[0] === '#')) {
                continue;
            }
            if (stripos($line, 'deny from all') !== false || stripos($line, 'Require all denied') !== false) {
                return true;
            }
        }
        return false;
    }
}
