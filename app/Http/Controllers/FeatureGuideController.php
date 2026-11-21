<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class FeatureGuideController extends Controller
{
    public function index(): View
    {
        return view('features.index');
    }
}
