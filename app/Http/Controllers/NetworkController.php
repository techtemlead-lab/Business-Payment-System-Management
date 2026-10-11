<?php

namespace App\Http\Controllers;

use App\Models\Dealer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NetworkController extends WorkspaceController
{
    public function hierarchy(Request $r): Response
    {
        $this->admin($r);

        return Inertia::render('Hierarchy', ['dealers' => $this->dealerRows(Dealer::query())]);
    }
}
