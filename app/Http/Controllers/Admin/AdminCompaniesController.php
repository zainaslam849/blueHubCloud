<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;

class AdminCompaniesController extends Controller
{
    public function index(Request $request)
    {
        $companies = Company::query()
            ->select('id', 'name', 'status')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $companies,
        ]);
    }
}
