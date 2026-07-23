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

namespace OrangeHRM\Tests\Claim\Dao;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\TransactionRequiredException;
use OrangeHRM\Claim\Dao\ClaimDao;
use OrangeHRM\Claim\Dto\ClaimRequestSearchFilterParams;
use OrangeHRM\Config\Config;
use OrangeHRM\Entity\ClaimRequest;
use OrangeHRM\ORM\Doctrine;
use OrangeHRM\Tests\Util\KernelTestCase;
use OrangeHRM\Tests\Util\TestDataService;

/**
 * @group Claim
 * @group Dao
 */
class ClaimDaoRequestTest extends KernelTestCase
{
    private ClaimDao $claimDao;

    protected function setUp(): void
    {
        $this->claimDao = new ClaimDao();
        $requestFixture = Config::get(Config::PLUGINS_DIR) . '/orangehrmClaimPlugin/test/fixtures/MyClaimRequestAPITest.yaml';
        TestDataService::populate($requestFixture);
    }

    public function testGetClaimRequestById(): void
    {
        $result = $this->claimDao->getClaimRequestById(1);
        $this->assertEquals(1, $result->getId());
    }

    public function testGetClaimRequestList(): void
    {
        $claimRequestSearchFilterParams = new ClaimRequestSearchFilterParams();
        $claimRequestSearchFilterParams->setEmpNumbers([4]);
        $claimRequests = $this->claimDao->getClaimRequestList($claimRequestSearchFilterParams);
        $this->assertEquals(4, count($claimRequests));
    }

    /**
     * The locked read must take a pessimistic write lock: Doctrine raises
     * TransactionRequiredException when a pessimistic-write query is issued without an
     * active transaction, which confirms the read is locked rather than a plain findOneBy().
     */
    public function testGetClaimRequestByIdForUpdateRequiresTransactionForLock(): void
    {
        $this->expectException(TransactionRequiredException::class);
        $this->claimDao->getClaimRequestByIdForUpdate(1);
    }

    public function testGetClaimRequestByIdForUpdateReturnsRequestWithinTransaction(): void
    {
        $connection = Doctrine::getEntityManager()->getConnection();
        $connection->beginTransaction();
        try {
            $result = $this->claimDao->getClaimRequestByIdForUpdate(1);
            $this->assertInstanceOf(ClaimRequest::class, $result);
            $this->assertEquals(1, $result->getId());
            $this->assertNull($this->claimDao->getClaimRequestByIdForUpdate(99999));
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Concurrency proof: the pessimistic write lock taken by getClaimRequestByIdForUpdate()
     * must actually serialise concurrent writers, not just emit a FOR UPDATE clause the
     * database ignores. Connection A (the EntityManager connection) holds the lock on claim
     * request #1 inside an open transaction; a second, independent connection B then tries to
     * lock the same row FOR UPDATE with a 1-second innodb_lock_wait_timeout. B must block on
     * A's lock and fail with a retryable lock-contention error (lock wait timeout), which is
     * the observable signature of the row being genuinely locked — the guard against the
     * TOCTOU race where a concurrent submit slips an expense into an already-submitted claim.
     *
     * This also runs the FOR UPDATE read against the real database, so any version-specific
     * incompatibility surfaces on every CI DB-compatibility matrix entry (MySQL / MariaDB).
     */
    public function testGetClaimRequestByIdForUpdateLocksRowAgainstConcurrentWriter(): void
    {
        $emConnection = Doctrine::getEntityManager()->getConnection();
        $secondConnection = DriverManager::getConnection($emConnection->getParams());
        // Fail fast instead of waiting the server default lock-wait timeout (often 50s).
        $secondConnection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $emConnection->beginTransaction();
        try {
            // A: acquire the pessimistic write lock on claim request #1.
            $locked = $this->claimDao->getClaimRequestByIdForUpdate(1);
            $this->assertInstanceOf(ClaimRequest::class, $locked);

            // B: a concurrent request tries to lock the same row the DAO locks.
            $secondConnection->beginTransaction();
            $lockContention = null;
            try {
                $secondConnection->executeQuery(
                    'SELECT id FROM ohrm_claim_request WHERE id = ? AND is_deleted = 0 FOR UPDATE',
                    [1]
                );
            } catch (RetryableException $e) {
                $lockContention = $e;
            }

            $this->assertInstanceOf(
                RetryableException::class,
                $lockContention,
                'A concurrent FOR UPDATE on the same claim request must block on the held lock '
                . 'and fail with a lock wait timeout, proving the row is pessimistically locked.'
            );
        } finally {
            if ($secondConnection->isTransactionActive()) {
                $secondConnection->rollBack();
            }
            $secondConnection->close();
            $emConnection->rollBack();
        }
    }
}
