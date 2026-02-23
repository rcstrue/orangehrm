# Payroll Architecture: ERP-Style Module Split

This document defines an ERP-style payroll design where each module maps to a business capability and data contract (not just code files).

## Design Principles

- Model modules around **domain ownership** and **inspection-ready outputs**.
- Keep core employee identity separate from payroll-period transactions.
- Treat statutory rules (PF/ESIC/Bonus/Wage compliance) as reusable policy components.
- Use immutable snapshots for payroll outputs once a run is locked.
- Support multi-client and multi-site segregation at both data and report levels.

---

## 1) Employee Master Module (Core)

### Primary table
`employee_master`

### Ownership
Source of truth for worker profile and statutory identity data.

### Key fields
- `employee_code` (unique business key)
- `name`
- `father_name`
- `aadhaar`
- `uan`
- `esic_no`
- `mobile`
- `client_id`
- `site_id`
- `department_id`
- `designation_id`
- `doj`
- `category` (Skilled / Semi / Unskilled)
- `minimum_wage_category`
- `bank_details`
- `pf_applicable`
- `esic_applicable`
- `salary_type` (Monthly / Daily)
- `status`

### Guardrails
- Do **not** store dynamic salary breakup here.
- Only keep static reference info needed across modules.

---

## 2) Salary Heads Module (Pay Components)

### Primary table
`salary_heads`

### Ownership
Defines reusable earning/deduction/employer-cost components.

### Sample heads
- Basic
- HRA
- Special Allowance
- DA
- Conveyance
- PF Employee
- PF Employer
- ESIC
- Bonus
- Leave Deduction
- OT
- Service Charge
- Admin Charges

### Key fields
- `head_id`
- `head_name`
- `type` (Earning / Deduction / Employer Cost)
- `calculation_rule`
- `formula`
- `statutory_flag`

### Notes
- Formula evaluation should be versioned and auditable.
- Mark statutory heads for special validation and export pipelines.

---

## 3) Employee Salary Structure Module

### Primary table
`employee_salary_structure`

### Ownership
Maps employees to pay heads with effective dating and revisions.

### Key fields
- `employee_code`
- `head_id`
- `amount_or_percentage`
- `effective_from`
- `revision_history_ref`

### Notes
- Enables increment and revision tracking.
- Effective-date rules should prevent overlapping active records for same employee + head.

---

## 4) Attendance / Monthly Input Module

### Primary table
`attendance`

### Ownership
Captures period inputs required by payroll calculation.

### Key fields
- `employee_code`
- `month`
- `present_days`
- `week_off`
- `ot_hours`
- `leave_days`
- `holidays`
- `site_transfer`
- `wage_rate`

### Notes
- Input should remain editable only before payroll lock.
- Preserve import source and timestamp for auditability.

---

## 5) Payroll Processing Module

### Primary table
`payroll_run`

### Ownership
Stores final monthly calculated wages and statutory bases.

### Key fields
- `employee_code`
- `month`
- `gross`
- `total_deduction`
- `net_pay`
- `pf_wage`
- `esic_wage`
- `bonus_wage`
- `lock_status`

### Processing states
- `DRAFT` → calculated but editable
- `VALIDATED` → checks passed
- `LOCKED` → immutable for statutory/report outputs

### Notes
- Lock record after approval.
- Any post-lock correction should create an adjustment entry, not destructive overwrite.

---

## 6) Wage Register Module (Derived Reporting)

### Artifact type
Generated report (not a primary transactional source).

### Source
Derived from `payroll_run` plus supporting metadata.

### Report coverage
- Days worked
- Rate
- Earnings
- Deductions
- Net pay

### Compliance forms
- Form XVII wage register
- Labour register variants where applicable

---

## 7) Payslip Module

### Artifact type
Generated PDF (or downloadable digital form).

### Data sources
- `employee_master`
- `payroll_run`
- `salary_heads` and employee structure mapping

### Notes
- Render snapshot data from locked payroll to ensure payslip reproducibility.

---

## Data Flow

```text
Employee Master
      ↓
Salary Structure
      ↓
Attendance Input
      ↓
Payroll Engine
      ↓
Payroll Run Table
      ↓
Reports → Wage Register → Payslip → Bank File → PF → ESIC
```

---

## Payroll Engine Rule Packs (Recommended)

Implement as modular validators/calculators so statutory changes are isolated:

1. **Minimum wage auto-validation**
   - Compare computed earnings vs category/site/client thresholds.
2. **PF ceiling logic**
   - Apply configurable ceiling and voluntary contribution flags.
3. **ESIC eligibility auto-detect**
   - Detect inclusion/exclusion from wage threshold and continuity rules.
4. **Bonus eligibility tracking**
   - Track statutory bonus qualification wage band and service period.
5. **Leave encashment**
   - Compute leave payout via policy-specific formula.
6. **OT multiplier**
   - Apply daily/hourly multiplier by policy and local law.
7. **Client billing export**
   - Generate billing lines by client/site/cost center.
8. **Labour register generation**
   - Auto-prepare required registers from locked payroll.
9. **Audit log**
   - Record who changed what and when for master/input/processing steps.
10. **Multi-client separation**
   - Enforce tenant-style partitioning and report scoping.
11. **Bank NEFT generation**
   - Produce bank transfer files from payable net salary.
12. **Contractor licence reporting**
   - Surface headcount, wage, and statutory views by contractor.
13. **Form XIII muster support**
   - Produce muster details from attendance and payroll data.
14. **Form XVII wage register output**
   - Ensure format-compatible export.
15. **PF ECR export**
   - Generate contribution file with UAN-wise details.
16. **ESIC upload sheet**
   - Generate insurable wage and contribution upload template.

---

## Suggested Supporting Tables

To keep core modules clean, introduce supporting tables:

- `payroll_run_line_items` (head-wise computed values per employee/month)
- `statutory_thresholds` (effective-dated PF/ESIC/minimum wage parameters)
- `payroll_audit_log` (entity, field, old value, new value, actor, timestamp)
- `payroll_lock_events` (who locked/unlocked and reason)
- `export_job_history` (bank/PF/ESIC export status and artifact links)

These tables reduce overloading on `payroll_run` and improve traceability.

## Implementation Guidance (Service Boundaries)

A maintainable service split:

- `EmployeeMasterService`
- `SalaryHeadService`
- `EmployeeSalaryStructureService`
- `AttendanceService`
- `PayrollCalculationService`
- `PayrollValidationService`
- `PayrollLockService`
- `PayrollReportingService`
- `StatutoryExportService`
- `AuditLogService`

Each service should expose stable APIs and avoid direct cross-module writes without domain events or orchestration.
