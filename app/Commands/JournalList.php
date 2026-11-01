<?php

namespace App\Commands;

class JournalList extends MrCommand
{
    protected $name        = 'mr:journal';
    protected $description = 'Shows the latest journal events.';
    protected $usage       = 'mr:journal [--limit=50] [--project=customer/project] [--json]';
    protected $options     = ['--limit' => 'Number of events (default 50)', '--project' => 'Only this project', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $limit   = max(1, min(1000, (int) ($this->optionString('limit') ?? 50)));
        $builder = db_connect()->table('journal')->orderBy('id', 'DESC')->limit($limit);
        if (($ref = $this->optionString('project')) !== null) {
            $builder->where('project_id', service('projectService')->get($ref)['id']);
        }
        $rows = array_reverse($builder->get()->getResultArray());
        $this->json() ? $this->outputJson($rows) : $this->outputTable($rows, ['id', 'created_at', 'event', 'source', 'actor', 'project_id', 'details']);

        return null;
    }
}
