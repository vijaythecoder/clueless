<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class AudioTestController extends Controller
{
    public function index()
    {
        return Inertia::render('AudioTest/Index');
    }
}