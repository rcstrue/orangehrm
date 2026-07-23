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

namespace OrangeHRM\Tests\Attendance\Dao;

use DateTime;
use DateTimeZone;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\TransactionRequiredException;
use Exception;
use OrangeHRM\Admin\Service\CompanyStructureService;
use OrangeHRM\Attendance\Dao\AttendanceDao;
use OrangeHRM\Attendance\Dto\AttendanceRecordSearchFilterParams;
use OrangeHRM\Attendance\Dto\EmployeeAttendanceSummarySearchFilterParams;
use OrangeHRM\Attendance\Exception\AttendanceServiceException;
use OrangeHRM\Config\Config;
use OrangeHRM\Core\Service\DateTimeHelperService;
use OrangeHRM\Framework\Services;
use OrangeHRM\ORM\Doctrine;
use OrangeHRM\Tests\Util\KernelTestCase;
use OrangeHRM\Tests\Util\TestDataService;
use OrangeHRM\Time\Dto\AttendanceReportSearchFilterParams;

/**
 * @group Attendance
 * @group Dao
 */
class AttendanceDaoTest extends KernelTestCase
{
    /**
     * @var AttendanceDao
     */
    private AttendanceDao $attendanceDao;

    /**
     * @var string
     */
    protected string $fixtures;

    /**
     * Set up method
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->attendanceDao = new AttendanceDao();
        $this->fixture = Config::get(
            Config::PLUGINS_DIR
        ).'/orangehrmAttendancePlugin/test/fixtures/AttendanceDaoTest.yaml';
        TestDataService::populate($this->fixture);
    }

    public function testGetLatestAttendanceRecordByEmployeeId(): void
    {
        $this->createKernelWithMockServices([Services::DATETIME_HELPER_SERVICE => new DateTimeHelperService()]);
        $attendanceRecord = $this->attendanceDao->getLatestAttendanceRecordByEmployeeNumber(1);
        $this->assertEquals(1, $attendanceRecord->getEmployee()->getEmpNumber());
        $this->assertEquals('Kayla', $attendanceRecord->getEmployee()->getFirstName());
        $this->assertEquals('Abbey', $attendanceRecord->getEmployee()->getLastName());
        $this->assertEquals('2011-05-27', $attendanceRecord->getPunchInUserTime()->format('Y-m-d'));
        $this->assertEquals('2011-05-27', $attendanceRecord->getPunchInUserTime()->format('Y-m-d'));
        $this->assertEquals('2011-05-28', $attendanceRecord->getPunchOutUtcTime()->format('Y-m-d'));
        $this->assertEquals('2011-05-28', $attendanceRecord->getPunchOutUserTime()->format('Y-m-d'));
        $this->assertEquals('PUNCHED OUT', $attendanceRecord->getState());
    }

    public function testGetAttendanceRecordById(): void
    {
        $attendanceRecord = $this->attendanceDao->getAttendanceRecordById(1);
        $this->assertEquals(1, $attendanceRecord->getId());
        $attendanceRecord2 = $this->attendanceDao->getAttendanceRecordById(10);
        $this->assertEmpty($attendanceRecord2);
    }

    public function testPunchInOverlapRecords(): void
    {
        $utcTimeZone = new DateTimeZone(DateTimeHelperService::TIMEZONE_UTC);

        try {
            $this->attendanceDao->checkForPunchInOverLappingRecords(
                new DateTime("2022-01-27 09:23:00", $utcTimeZone),
                4
            );
        } catch (Exception $exception) {
            $this->assertTrue($exception instanceof AttendanceServiceException);
            $this->assertEquals("Cannot Proceed Punch In Employee Already Punched In", $exception->getMessage());
        }

        $overlapStatus = $this->attendanceDao->checkForPunchInOverLappingRecords(
            new DateTime("2011-04-22 09:25:00", $utcTimeZone),
            5
        );
        $this->assertFalse($overlapStatus);

        $overlapStatus = $this->attendanceDao->checkForPunchInOverLappingRecords(
            new DateTime("2011-04-21 09:26:00", $utcTimeZone),
            5
        );
        $this->assertTrue($overlapStatus);
    }

    /**
     * The locked overlap check must take a pessimistic write lock: Doctrine raises
     * TransactionRequiredException when a pessimistic-write query runs without an active
     * transaction, which confirms the overlap read is locked rather than a plain SELECT.
     */
    public function testCheckForPunchInOverLappingRecordsForUpdateRequiresTransactionForLock(): void
    {
        $utcTimeZone = new DateTimeZone(DateTimeHelperService::TIMEZONE_UTC);
        $this->expectException(TransactionRequiredException::class);
        $this->attendanceDao->checkForPunchInOverLappingRecordsForUpdate(
            new DateTime("2011-04-22 09:25:00", $utcTimeZone),
            5
        );
    }

    public function testCheckForPunchInOverLappingRecordsForUpdateWithinTransaction(): void
    {
        $utcTimeZone = new DateTimeZone(DateTimeHelperService::TIMEZONE_UTC);
        $connection = Doctrine::getEntityManager()->getConnection();
        $connection->beginTransaction();
        try {
            // Employee 5 last punched out; a fresh punch-in does not overlap.
            $this->assertFalse(
                $this->attendanceDao->checkForPunchInOverLappingRecordsForUpdate(
                    new DateTime("2011-04-22 09:25:00", $utcTimeZone),
                    5
                )
            );
            // An overlapping time for the same employee is detected.
            $this->assertTrue(
                $this->attendanceDao->checkForPunchInOverLappingRecordsForUpdate(
                    new DateTime("2011-04-21 09:26:00", $utcTimeZone),
                    5
                )
            );
            // Employee 4 is already punched in: a second punch-in must be rejected.
            $this->expectException(AttendanceServiceException::class);
            $this->attendanceDao->checkForPunchInOverLappingRecordsForUpdate(
                new DateTime("2022-01-27 09:23:00", $utcTimeZone),
                4
            );
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Concurrency proof for the punch-in overlap lock: two simultaneous punch-ins for the
     * same employee must be serialised so they cannot both pass the overlap check and create
     * duplicate open records. Connection A (the EntityManager connection) holds the
     * pessimistic write lock taken by checkForPunchInOverLappingRecordsForUpdate(), which
     * locks the employee's latest attendance record. A second, independent connection B then
     * runs the same latest-record FOR UPDATE query with a 1-second innodb_lock_wait_timeout
     * and must block on A's lock, failing with a retryable lock-contention error — the
     * observable signature of a genuine row lock.
     *
     * This also runs the FOR UPDATE read against the real database, so any version-specific
     * incompatibility surfaces on every CI DB-compatibility matrix entry (MySQL / MariaDB).
     */
    public function testCheckForPunchInOverLappingRecordsForUpdateLocksLatestRecordAgainstConcurrentPunchIn(): void
    {
        $utcTimeZone = new DateTimeZone(DateTimeHelperService::TIMEZONE_UTC);
        $emConnection = Doctrine::getEntityManager()->getConnection();
        $secondConnection = DriverManager::getConnection($emConnection->getParams());
        // Fail fast instead of waiting the server default lock-wait timeout (often 50s).
        $secondConnection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $emConnection->beginTransaction();
        try {
            // Employee 5 last punched out: this call locks their latest record (no overlap).
            $this->assertFalse(
                $this->attendanceDao->checkForPunchInOverLappingRecordsForUpdate(
                    new DateTime("2011-04-22 09:25:00", $utcTimeZone),
                    5
                )
            );

            // A concurrent punch-in for the same employee locks the same latest record.
            $secondConnection->beginTransaction();
            $lockContention = null;
            try {
                $secondConnection->executeQuery(
                    'SELECT id FROM ohrm_attendance_record WHERE employee_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE',
                    [5]
                );
            } catch (RetryableException $e) {
                $lockContention = $e;
            }

            $this->assertInstanceOf(
                RetryableException::class,
                $lockContention,
                'A concurrent punch-in must block on the latest-record write lock held by the '
                . 'first transaction, proving punch-ins for the same employee are serialised.'
            );
        } finally {
            if ($secondConnection->isTransactionActive()) {
                $secondConnection->rollBack();
            }
            $secondConnection->close();
            $emConnection->rollBack();
        }
    }

    public function testPunchOutOverlapRecords(): void
    {
        $utcTimeZone = new DateTimeZone(DateTimeHelperService::TIMEZONE_UTC);
        try {
            $this->attendanceDao->checkForPunchOutOverLappingRecords(
                new DateTime("2022-01-27 09:23:00", $utcTimeZone),
                1
            );
        } catch (Exception $exception) {
            $this->assertTrue($exception instanceof AttendanceServiceException);
            $this->assertEquals("Cannot Proceed Punch Out Employee Already Punched Out", $exception->getMessage());
        }

        try {
            $this->attendanceDao->checkForPunchOutOverLappingRecords(
                new DateTime("2022-01-27 09:20:00", $utcTimeZone),
                4
            );
        } catch (Exception $exception) {
            $this->assertTrue($exception instanceof AttendanceServiceException);
            $this->assertEquals("Punch Out Time Should Be Later Than Punch In Time", $exception->getMessage());
        }

        $overlapStatus = $this->attendanceDao->checkForPunchOutOverLappingRecords(
            new DateTime("2011-04-21 09:26:00", $utcTimeZone),
            2
        );
        $this->assertFalse($overlapStatus);

        $overlapStatus = $this->attendanceDao->checkForPunchOutOverLappingRecords(
            new DateTime("2011-04-20 09:29:00", $utcTimeZone),
            2
        );
        $this->assertTrue($overlapStatus);
    }

    public function testAttendanceSummeryReport(): void
    {
        $this->fixture = Config::get(Config::PLUGINS_DIR) . '/orangehrmTimePlugin/test/fixtures/AttendanceReportDataAPITest.yaml';
        TestDataService::populate($this->fixture);
        $attendanceReportSearchFilterParams = new AttendanceReportSearchFilterParams();
        $result = $this->attendanceDao->getAttendanceReportCriteriaList($attendanceReportSearchFilterParams);
        $totalRecords = $this->attendanceDao->getAttendanceReportCriteriaListCount($attendanceReportSearchFilterParams);
        $totalHours = $this->attendanceDao->getTotalAttendanceDuration($attendanceReportSearchFilterParams);
        $this->assertEquals("Kayla Abbey", $result[0]['fullName']);
        $this->assertEquals("64800", $result[0]['total']);
        $this->assertEquals(1, $result[0]['empNumber']);
        $this->assertEquals(10, $totalRecords);
        $this->assertEquals(250200, $totalHours);


        $attendanceReportSearchFilterParams = new AttendanceReportSearchFilterParams();
        $attendanceReportSearchFilterParams->setFromDate(new DateTime("2011-01-01"));
        $result = $this->attendanceDao->getAttendanceReportCriteriaList($attendanceReportSearchFilterParams);
        $totalRecords = $this->attendanceDao->getAttendanceReportCriteriaListCount($attendanceReportSearchFilterParams);
        $totalHours = $this->attendanceDao->getTotalAttendanceDuration($attendanceReportSearchFilterParams);
        $this->assertEquals("Ashley Abel", $result[1]['fullName']);
        $this->assertEquals("32400", $result[1]['total']);
        $this->assertEquals(10, $totalRecords);
        $this->assertEquals(250200, $totalHours);

        $attendanceReportSearchFilterParams = new AttendanceReportSearchFilterParams();
        $attendanceReportSearchFilterParams->setToDate(new DateTime("2011-12-31"));
        $result = $this->attendanceDao->getAttendanceReportCriteriaList($attendanceReportSearchFilterParams);
        $totalRecords = $this->attendanceDao->getAttendanceReportCriteriaListCount($attendanceReportSearchFilterParams);
        $totalHours = $this->attendanceDao->getTotalAttendanceDuration($attendanceReportSearchFilterParams);
        $this->assertEquals("mahatma gandhi", $result[2]['fullName']);
        $this->assertEquals("86460", $result[2]['total']);
        $this->assertEquals(10, $totalRecords);
        $this->assertEquals(217800, $totalHours);

        $attendanceReportSearchFilterParams = new AttendanceReportSearchFilterParams();
        $attendanceReportSearchFilterParams->setFromDate(new DateTime("2011-01-01"));
        $attendanceReportSearchFilterParams->setToDate(new DateTime("2021-01-31"));
        $result = $this->attendanceDao->getAttendanceReportCriteriaList($attendanceReportSearchFilterParams);
        $this->assertCount(10, $result);

        $attendanceReportSearchFilterParams = new AttendanceReportSearchFilterParams();
        $this->createKernelWithMockServices(
            [
                Services::COMPANY_STRUCTURE_SERVICE => new CompanyStructureService(),
            ]
        );
        $attendanceReportSearchFilterParams->setFromDate(new DateTime("2011-01-01"));
        $attendanceReportSearchFilterParams->setToDate(new DateTime("2021-12-31"));
        $attendanceReportSearchFilterParams->setJobTitleId(1);
        $attendanceReportSearchFilterParams->setEmploymentStatusId(1);
        $attendanceReportSearchFilterParams->setSubUnitId(2);
        $result = $this->attendanceDao->getAttendanceReportCriteriaList($attendanceReportSearchFilterParams);
        $totalRecords = $this->attendanceDao->getAttendanceReportCriteriaListCount($attendanceReportSearchFilterParams);
        $totalHours = $this->attendanceDao->getTotalAttendanceDuration($attendanceReportSearchFilterParams);
        $this->assertEquals("Kayla Abbey", $result[0]['fullName']);
        $this->assertEquals(1, $result[0]['empNumber']);
        $this->assertNull($result[0]['terminationId']);
        $this->assertEquals("Adolf Hitler", $result[1]['fullName']);
        $this->assertEquals(5, $result[1]['empNumber']);
        $this->assertNull($result[1]['terminationId']);
        $this->assertEquals(2, $totalRecords);
        $this->assertEquals(32700, $totalHours);

        $attendanceReportSearchFilterParams->setFromDate(new DateTime("2011-01-01"));
        $attendanceReportSearchFilterParams->setToDate(new DateTime("2021-12-31"));
        $attendanceReportSearchFilterParams->setEmployeeNumbers([1]);
        $attendanceReportSearchFilterParams->setJobTitleId(1);
        $attendanceReportSearchFilterParams->setEmploymentStatusId(1);
        $attendanceReportSearchFilterParams->setSubUnitId(2);
        $result = $this->attendanceDao->getAttendanceReportCriteriaList($attendanceReportSearchFilterParams);
        $totalRecords = $this->attendanceDao->getAttendanceReportCriteriaListCount($attendanceReportSearchFilterParams);
        $totalHours = $this->attendanceDao->getTotalAttendanceDuration($attendanceReportSearchFilterParams);
        $this->assertEquals("Kayla Abbey", $result[0]['fullName']);
        $this->assertEquals(1, $result[0]['empNumber']);
        $this->assertNull($result[0]['terminationId']);
        $this->assertEquals(1, $totalRecords);
        $this->assertEquals(32400, $totalHours);
    }

    public function testAttendanceList(): void
    {
        $attendanceRecordSearchFilterParams = new AttendanceRecordSearchFilterParams();
        $attendanceRecordSearchFilterParams->setFromDate(new DateTime("2011-04-20 00:00:00"));
        $attendanceRecordSearchFilterParams->setToDate(new DateTime("2011-04-20 23:59:59"));
        $attendanceRecordSearchFilterParams->setEmployeeNumbers([2]);
        $attendanceRecords = $this->attendanceDao->getAttendanceRecordList($attendanceRecordSearchFilterParams);
        $attendanceRecordCount = $this->attendanceDao->getAttendanceRecordListCount(
            $attendanceRecordSearchFilterParams
        );
        $attendanceRecordDuration = $this->attendanceDao->getTotalWorkingTime($attendanceRecordSearchFilterParams);

        $this->assertEquals("Ashley Abel", $attendanceRecords[0]['fullName']);
        $this->assertEquals(2, $attendanceRecords[0]['empNumber']);
        $this->assertNull($attendanceRecords[0]['terminationId']);
        $this->assertEquals(1, $attendanceRecordCount);
        $this->assertNull($attendanceRecordDuration['total']);
    }

    public function testEmployeeAttendanceSummaryList(): void
    {
        $employeeAttendanceSummarySearchFilterParams = new EmployeeAttendanceSummarySearchFilterParams();
        $employeeAttendanceSummarySearchFilterParams->setFromDate(new DateTime("2011-04-20 00:00:00"));
        $employeeAttendanceSummarySearchFilterParams->setToDate(new DateTime("2011-04-20 23:59:59"));
        $employeeAttendanceSummarySearchFilterParams->setEmployeeNumbers(null);
        $attendanceRecords = $this->attendanceDao->getEmployeeAttendanceSummaryList(
            $employeeAttendanceSummarySearchFilterParams
        );
        $attendanceRecordCount = $this->attendanceDao->getEmployeeAttendanceSummaryListCount(
            $employeeAttendanceSummarySearchFilterParams
        );

        $this->assertEquals("Kayla", $attendanceRecords[0]['firstName']);
        $this->assertEquals("Abbey", $attendanceRecords[0]['lastName']);
        $this->assertEquals(1, $attendanceRecords[0]['empNumber']);
        $this->assertNull($attendanceRecords[0]['terminationId']);
        $this->assertEquals(5, $attendanceRecordCount);

        $employeeAttendanceSummarySearchFilterParams = new EmployeeAttendanceSummarySearchFilterParams();
        $employeeAttendanceSummarySearchFilterParams->setFromDate(new DateTime("2011-04-20 00:00:00"));
        $employeeAttendanceSummarySearchFilterParams->setToDate(new DateTime("2011-04-20 23:59:59"));
        $employeeAttendanceSummarySearchFilterParams->setEmployeeNumbers([3]);
        $attendanceRecords = $this->attendanceDao->getEmployeeAttendanceSummaryList(
            $employeeAttendanceSummarySearchFilterParams
        );
        $attendanceRecordCount = $this->attendanceDao->getEmployeeAttendanceSummaryListCount(
            $employeeAttendanceSummarySearchFilterParams
        );

        $this->assertEquals("Renukshan", $attendanceRecords[0]['firstName']);
        $this->assertEquals("Saputhanthri", $attendanceRecords[0]['lastName']);
        $this->assertEquals(3, $attendanceRecords[0]['empNumber']);
        $this->assertNull($attendanceRecords[0]['terminationId']);
        $this->assertEquals(1, $attendanceRecordCount);
        $this->assertEquals("300", $attendanceRecords[0]['total']);
    }
}
