<?php

namespace Config;

use App\Core\Access\ApiTokenService;
use App\Core\Access\SmtpCredentialService;
use App\Core\Journal\JournalWriter;
use App\Core\Projects\CustomerService;
use App\Core\Projects\ProjectService;
use App\Modules\Receive\Libraries\RouteService;
use CodeIgniter\Config\BaseService;

/**
 * Application services. The CLI and (later) the Admin API use the same
 * instances, so both apply identical validation, revisions and journaling.
 */
class Services extends BaseService
{
    public static function journalWriter(bool $getShared = true): JournalWriter
    {
        if ($getShared) {
            return static::getSharedInstance('journalWriter');
        }

        return new JournalWriter(db_connect());
    }

    public static function customerService(bool $getShared = true): CustomerService
    {
        if ($getShared) {
            return static::getSharedInstance('customerService');
        }

        return new CustomerService(db_connect(), static::journalWriter());
    }

    public static function projectService(bool $getShared = true): ProjectService
    {
        if ($getShared) {
            return static::getSharedInstance('projectService');
        }

        return new ProjectService(db_connect(), static::journalWriter(), static::customerService());
    }

    public static function apiTokenService(bool $getShared = true): ApiTokenService
    {
        if ($getShared) {
            return static::getSharedInstance('apiTokenService');
        }

        return new ApiTokenService(db_connect(), static::journalWriter(), static::projectService());
    }

    public static function smtpCredentialService(bool $getShared = true): SmtpCredentialService
    {
        if ($getShared) {
            return static::getSharedInstance('smtpCredentialService');
        }

        return new SmtpCredentialService(db_connect(), static::journalWriter(), static::projectService());
    }

    public static function routeService(bool $getShared = true): RouteService
    {
        if ($getShared) {
            return static::getSharedInstance('routeService');
        }

        return new RouteService(db_connect(), static::journalWriter(), static::projectService());
    }

    public static function webhookService(bool $getShared = true): \App\Core\Webhooks\WebhookService
    {
        if ($getShared) {
            return static::getSharedInstance('webhookService');
        }

        return new \App\Core\Webhooks\WebhookService(db_connect(), static::journalWriter(), config('Mailrunner')->dataDir . '/webhook.request');
    }
}
