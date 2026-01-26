<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WeeklyCallReport;

class WeeklyCallReportPolicy
{
    /**
     * Determine if the user can view any weekly call reports.
     *
     * Only admin users can access the reports list.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine if the user can view a specific weekly call report.
     *
     * Admin users can view all reports.
     * Non-admin users can only view reports for their own company.
     */
    public function view(User $user, WeeklyCallReport $report): bool
    {
        // Admins can view all reports
        if ($user->isAdmin()) {
            return true;
        }

        // Non-admin users can only view reports for their company
        return $user->company_id === $report->company_id;
    }

    /**
     * Determine if the user can create weekly call reports.
     *
     * Only admin users can trigger report generation.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine if the user can update a weekly call report.
     *
     * Only admin users can modify reports.
     */
    public function update(User $user, WeeklyCallReport $report): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine if the user can delete a weekly call report.
     *
     * Only admin users can delete reports.
     */
    public function delete(User $user, WeeklyCallReport $report): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine if the user can export a weekly call report (PDF/CSV).
     *
     * Admin users can export all reports.
     * Non-admin users can only export reports for their own company.
     */
    public function export(User $user, WeeklyCallReport $report): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->company_id === $report->company_id;
    }
}
