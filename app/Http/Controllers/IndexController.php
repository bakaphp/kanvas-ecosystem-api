<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class IndexController extends BaseController
{
    /**
     * Index.
     *
     * @method GET
     * @url /
     *
     * @return Response
     */
    public function index(Request $request) : JsonResponse
    {
       // print_r(app('userData')->toArray());
       //print_R(auth());
        //echo $request->user();
        //print_r($request->user()); die();
        return response()->json(['Woot Kanvas']);
    }

    /**
     * Show the status of the different services.
     *
     * @method GET
     * @url /status
     *
     * @return Response
     */
    public function status() : JsonResponse
    {
        return parent::status();
    }
}
