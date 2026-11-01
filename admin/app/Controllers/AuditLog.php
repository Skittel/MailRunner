<?php

namespace App\Controllers;

class AuditLog extends BaseController
{
    public function index()
    {
        return view('audit/index', ['rows' => db_connect()->table('audit_log a')->select('a.*, u.name AS user_name')
            ->join('users u', 'u.id = a.user_id', 'left')->orderBy('a.id', 'DESC')->limit(300)->get()->getResultArray()]);
    }
}
