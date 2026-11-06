<?php

namespace App\Modules\Receive\Libraries;

use App\Core\Journal\JournalWriter;
use App\Core\Projects\ProjectService;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

/**
 * Receive routes of a project. Always replaced as a complete list.
 * A route is a domain (all addresses, no subdomains) or a single address;
 * each route belongs to exactly one project on the node.
 */
class RouteService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly ProjectService $projects,
    ) {
    }

    /**
     * Replaces all routes of the project. An empty list removes all routes.
     *
     * @param list<string> $routes
     *
     * @return list<array{route_type: string, route: string}>
     */
    public function set(string $ref, array $routes, ChangeContext $ctx): array
    {
        $wanted = [];
        foreach ($routes as $route) {
            $normalized                     = self::normalize($route);
            $wanted[$normalized['route']] = $normalized;
        }

        return Transaction::run($this->db, function () use ($ref, $wanted, $ctx): array {
            $project = $this->projects->getForChange($ref, $ctx);
            if ($wanted !== [] && ($project['type'] ?? 'receive') !== 'receive') {
                throw new ConfigException('PROJECT_TYPE', 'Project "' . $ref . '" is a send project; routes (receive domains) belong to receive projects');
            }

            if ($wanted !== []) {
                $conflicts = $this->db->table('receive_routes r')
                    ->select('r.route, c.customer_key, p.project_key')
                    ->join('projects p', 'p.id = r.project_id')
                    ->join('customers c', 'c.id = p.customer_id')
                    ->whereIn('r.route', array_keys($wanted))
                    ->where('r.project_id !=', $project['id'])
                    ->get()->getResultArray();
                if ($conflicts !== []) {
                    $list = array_map(static fn ($c) => $c['route'] . ' (' . $c['customer_key'] . '/' . $c['project_key'] . ')', $conflicts);

                    throw new ConfigException('ROUTE_CONFLICT', 'Route(s) already owned by another project: ' . implode(', ', $list));
                }
            }

            $current = array_column(
                $this->db->table('receive_routes')->where('project_id', $project['id'])->get()->getResultArray(),
                null,
                'route',
            );
            $added   = array_values(array_diff(array_keys($wanted), array_keys($current)));
            $removed = array_values(array_diff(array_keys($current), array_keys($wanted)));

            if ($removed !== []) {
                $this->db->table('receive_routes')->where('project_id', $project['id'])->whereIn('route', $removed)->delete();
            }
            foreach ($added as $route) {
                $this->db->table('receive_routes')->insert([
                    'project_id' => $project['id'],
                    'route_type' => $wanted[$route]['route_type'],
                    'route'      => $route,
                    'created_at' => Time::now(),
                ]);
            }

            $revision = $this->projects->bumpRevision((int) $project['id']);
            $this->journal->append('routes.updated', $ctx, (int) $project['customer_id'], (int) $project['id'], [
                'added' => $added, 'removed' => $removed, 'revision' => $revision,
            ]);

            return $this->list($ref);
        });
    }

    /**
     * @return list<array{route_type: string, route: string}>
     */
    public function list(string $ref): array
    {
        $project = $this->projects->get($ref);

        return $this->db->table('receive_routes')->select('route_type, route')
            ->where('project_id', $project['id'])->orderBy('route')->get()->getResultArray();
    }

    /**
     * @return array{route_type: string, route: string}
     */
    public static function normalize(string $route): array
    {
        $route = strtolower(trim($route));
        if (str_contains($route, '@')) {
            [$local, $domain] = explode('@', $route, 2);
            if (preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~.-]{1,64}$/', $local) !== 1 || str_starts_with($local, '.') || str_ends_with($local, '.')) {
                throw new ConfigException('INVALID_ROUTE', 'Invalid address "' . $route . '"');
            }

            return ['route_type' => 'address', 'route' => $local . '@' . self::normalizeDomain($domain, $route)];
        }

        return ['route_type' => 'domain', 'route' => self::normalizeDomain($route, $route)];
    }

    private static function normalizeDomain(string $domain, string $original): string
    {
        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253
            || preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $ascii) !== 1) {
            throw new ConfigException('INVALID_ROUTE', 'Invalid domain in "' . $original . '"');
        }

        return $ascii;
    }
}
