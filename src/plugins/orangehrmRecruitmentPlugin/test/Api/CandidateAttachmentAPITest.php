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

namespace OrangeHRM\Tests\Recruitment\Api;

use OrangeHRM\Config\Config;
use OrangeHRM\Core\Api\V2\Exception\ForbiddenException;
use OrangeHRM\Core\Api\V2\Validator\ParamRule;
use OrangeHRM\Core\Api\V2\Validator\ParamRuleCollection;
use OrangeHRM\Core\Api\V2\Validator\Rules;
use OrangeHRM\Core\Authorization\Manager\BasicUserRoleManager;
use OrangeHRM\Entity\Candidate;
use OrangeHRM\Framework\Services;
use OrangeHRM\Recruitment\Api\CandidateAttachmentAPI;
use OrangeHRM\Tests\Util\EndpointTestCase;
use OrangeHRM\Tests\Util\TestDataService;

/**
 * @group Recruitment
 * @group APIv2
 */
class CandidateAttachmentAPITest extends EndpointTestCase
{
    /**
     * Candidate the mocked role manager can access.
     */
    private const ACCESSIBLE_CANDIDATE_ID = 1;

    /**
     * Candidate that exists but is OUTSIDE the user's accessible scope — the IDOR target.
     */
    private const INACCESSIBLE_CANDIDATE_ID = 2;

    protected function setUp(): void
    {
        $fixture = Config::get(Config::PLUGINS_DIR)
            . '/orangehrmRecruitmentPlugin/test/fixtures/CandidateAttachmentEntityTest.yaml';
        TestDataService::populate($fixture);
    }

    // ---------------------------------------------------------------------------------------------
    // Structural guards: the candidateId param must be wired with an IN_ACCESSIBLE_ENTITY_ID rule.
    // Cheap and fast — they fail immediately if the rule is removed or pointed at the wrong entity.
    // ---------------------------------------------------------------------------------------------

    /**
     * GET /api/v2/recruitment/candidate/{candidateId}/attachment must enforce per-record
     * access scope so a HiringManager/Interviewer cannot read attachment metadata of
     * candidates outside their accessible vacancies.
     */
    public function testGetValidationRuleForGetOneEnforcesCandidateAccessScope(): void
    {
        $api = new CandidateAttachmentAPI($this->getRequest());
        $this->assertCandidateIdGatedByAccessibleEntityRule($api->getValidationRuleForGetOne());
    }

    /**
     * PUT /api/v2/recruitment/candidate/{candidateId}/attachment (which can permanently
     * delete a resume via currentAttachment=deleteCurrent) must enforce per-record access scope.
     */
    public function testGetValidationRuleForUpdateEnforcesCandidateAccessScope(): void
    {
        $api = new CandidateAttachmentAPI($this->getRequest());
        $this->assertCandidateIdGatedByAccessibleEntityRule($api->getValidationRuleForUpdate());
    }

    /**
     * POST /api/v2/recruitment/candidate/attachments must enforce per-record access scope too,
     * so an attachment cannot be created against a candidate the user cannot access.
     */
    public function testGetValidationRuleForCreateEnforcesCandidateAccessScope(): void
    {
        $api = new CandidateAttachmentAPI($this->getRequest());
        $this->assertCandidateIdGatedByAccessibleEntityRule($api->getValidationRuleForCreate());
    }

    // ---------------------------------------------------------------------------------------------
    // Behavioural enforcement: drive the actual candidateId rule the API builds through the
    // validator. With a role manager that only grants access to ACCESSIBLE_CANDIDATE_ID, an
    // existing-but-inaccessible candidate must be rejected with ForbiddenException (the IDOR the
    // fix closes), while the accessible candidate passes. A structural assertion alone would not
    // catch the rule being wired with the wrong entity, a wrong option, or the validator failing
    // to enforce it.
    // ---------------------------------------------------------------------------------------------

    public function testGetOneRejectsAttachmentAccessForInaccessibleCandidate(): void
    {
        $api = new CandidateAttachmentAPI($this->getRequest());
        $this->assertCandidateIdAccessScopeIsEnforced($api->getValidationRuleForGetOne());
    }

    public function testUpdateRejectsAttachmentMutationForInaccessibleCandidate(): void
    {
        $api = new CandidateAttachmentAPI($this->getRequest());
        $this->assertCandidateIdAccessScopeIsEnforced($api->getValidationRuleForUpdate());
    }

    public function testCreateRejectsAttachmentForInaccessibleCandidate(): void
    {
        $api = new CandidateAttachmentAPI($this->getRequest());
        $this->assertCandidateIdAccessScopeIsEnforced($api->getValidationRuleForCreate());
    }

    /**
     * Runs the candidateId param rule (exactly as the API built it) through the validator under a
     * role manager scoped to a single candidate, asserting the accessible candidate passes and the
     * existing inaccessible candidate is blocked with ForbiddenException.
     */
    private function assertCandidateIdAccessScopeIsEnforced(ParamRuleCollection $collection): void
    {
        $userRoleManager = $this->getMockBuilder(BasicUserRoleManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAccessibleEntityIds'])
            ->getMock();
        $userRoleManager->method('getAccessibleEntityIds')
            ->willReturn([self::ACCESSIBLE_CANDIDATE_ID]);
        $this->createKernelWithMockServices([Services::USER_ROLE_MANAGER => $userRoleManager]);

        // Validate only the candidateId rule the API wired — isolating the access-scope guard from
        // the other params (attachment, currentAttachment) the create/update collections require.
        $candidateIdRules = new ParamRuleCollection($this->getCandidateIdParamRule($collection));

        $this->assertTrue(
            $this->validate(
                [CandidateAttachmentAPI::PARAMETER_CANDIDATE_ID => self::ACCESSIBLE_CANDIDATE_ID],
                $candidateIdRules
            ),
            'An accessible candidate must pass the candidateId access-scope check'
        );

        $forbidden = null;
        try {
            $this->validate(
                [CandidateAttachmentAPI::PARAMETER_CANDIDATE_ID => self::INACCESSIBLE_CANDIDATE_ID],
                $candidateIdRules
            );
        } catch (ForbiddenException $e) {
            $forbidden = $e;
        }
        $this->assertInstanceOf(
            ForbiddenException::class,
            $forbidden,
            'Accessing an existing candidate outside the user\'s scope must be forbidden (IDOR guard)'
        );
    }

    private function assertCandidateIdGatedByAccessibleEntityRule(ParamRuleCollection $collection): void
    {
        $candidateIdRule = $this->getCandidateIdParamRule($collection);

        $this->assertNotNull(
            $candidateIdRule,
            'Expected a validation rule for the candidateId parameter'
        );

        $hasAccessibleEntityRule = false;
        foreach ($candidateIdRule->getRules() as $rule) {
            if ($rule->getRuleClass() === Rules::IN_ACCESSIBLE_ENTITY_ID
                && in_array(Candidate::class, $rule->getRuleConstructorParams(), true)) {
                $hasAccessibleEntityRule = true;
                break;
            }
        }

        $this->assertTrue(
            $hasAccessibleEntityRule,
            'candidateId must be guarded by an IN_ACCESSIBLE_ENTITY_ID rule against Candidate::class '
            . 'to prevent cross-candidate access'
        );
    }

    private function getCandidateIdParamRule(ParamRuleCollection $collection): ?ParamRule
    {
        foreach ($collection->getParamValidations() as $paramRule) {
            if ($paramRule instanceof ParamRule
                && $paramRule->getParamKey() === CandidateAttachmentAPI::PARAMETER_CANDIDATE_ID) {
                return $paramRule;
            }
        }
        return null;
    }
}
