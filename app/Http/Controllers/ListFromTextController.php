<?php

namespace App\Http\Controllers;

use App\Services\ListService;
use Illuminate\Http\Request;

class ListFromTextController extends Controller
{
    public function __invoke(Request $request, ListService $list)
    {
        $request->validate(['text' => 'required|string']);
        $result = $list->processCommand($request->string('text'));

        return response()->json([
            'heard'  => $request->string('text'),
            'action' => $result['action'],
            'items'  => $result['items'],
        ]);
    }
}
