<?php

namespace Pomodone\Providers;


use Carbon\Carbon;
use GuzzleHttp\Client as GuzzleHttpClient;
use ohmy\Auth2;
use PHPExtra\Sorter\Sorter;
use PHPExtra\Sorter\Strategy\ComplexSortStrategy;
use Pomodone\DataService;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Pool;

class Wunderlist extends BaseProvider
{
    const NAME = 'wunderlist';
    const TITLE = 'Wunderlist';

    public function itemsFromSelectedContainers(array $service, array $filters = [])
    {
        $final_json = [
            'projects' => [],
            'cards' => [],
            'lists' => []
        ];


            $client = new GuzzleHttpClient([
                'base_url' => 'https://a.wunderlist.com/api/v1/',
                'defaults' => [
                    'headers' => [
                        'X-Client-ID' => $this->key,
                        'X-Access-Token' => "{$service['oauth_token']}",
                    ]
                ]
            ]);


            $sort = $this->getSort($service);

            $final_json['sources'][] = [
                'uuid' => self::NAME,
                'title' => self::TITLE,
                'sortIndex' => $sort
            ];

            $selected_datasets = array_column($service['datasets'], 'sortIndex', 'id');
            $datasets_buckets = array_column($service['datasets'], 'accessLevel', 'id');

            $today_cards = [];
            $starred_cards = [];

            $final_json['projects'][] = [
                'uuid' => self::NAME.'-today',
                'source' => self::NAME,
                'title' => 'Today',
                'sortIndex' => -1,
                'accessLevel' => 1,
            ];


            $final_json['projects'][] = [
                'uuid' => self::NAME.'-starred',
                'source' => self::NAME,
                'title' => 'Starred',
                'sortIndex' => 0,
                'accessLevel' => 1,
            ];

            $lists = $client->get('lists')->json();

            $list_ids = array_column($lists, 'id');

            foreach ($service['datasets'] as $board) {
                
                if(!in_array($board['id'], $list_ids)) continue;

                if(!empty($filters['project']) && !in_array($board['id'], $filters['project'])) continue;

                $final_json['projects'][] = [
                    'uuid' => $board['id'],
                    'source' => self::NAME,
                    'title' => $board['title'],
                    'sortIndex' => $selected_datasets[$board['id']],
                    'accessLevel' => $datasets_buckets[$board['id']],
                ];

                $final_json['lists'][] = [
                    'uuid' => "tasks-{$board['id']}",
                    'source' => self::NAME,
                    'title' => 'Tasks',
                    'parent' => $board['id'],
                    'default' => true,
                    'can_create_new' => $this->canCreateNew()
                ];

                $final_json['lists'][] = [
                    'uuid' => "starred-{$board['id']}",
                    'source' => self::NAME,
                    'title' => $board['title'],
                    'parent' => self::NAME.'-starred',
                    'default' => true,
                    'can_create_new' => $this->canCreateNew()
                ];

                $final_json['lists'][] = [
                    'uuid' => "today-{$board['id']}",
                    'source' => self::NAME,
                    'title' => $board['title'],
                    'parent' => self::NAME.'-today',
                    'default' => true,
                    'can_create_new' => $this->canCreateNew()
                ];

                try {
                    $user = $this->app['session']->get('user');
                    $user_tz = new \DateTimeZone(empty($user['timezone']) ? 'UTC' : $user['timezone']);

                    $tasks = $client->get('tasks', ['query' => ['list_id' => $board['id']]])->json();

                    $task_order = $client->get('task_positions', ['query' => ['list_id' => $board['id']]])->json();

                    $task_order_actual = [];

                    if(is_array($task_order) && count($task_order) > 0) {
                        $task_order_actual = reset($task_order);
                    }

                    foreach ($tasks as $task) {
                        $today = array_key_exists('due_date', $task) && (Carbon::createFromTimestamp(strtotime($task['due_date']), $user_tz)->isToday() || Carbon::createFromTimestamp(strtotime($task['due_date']), $user_tz)->isPast());
                        $starred = array_key_exists('starred', $task);

                        $card_data = [
                            'title' => $task['title'],
                            'source' => self::NAME,
                            'uuid' => $task['id'],
                            'parent' => "tasks-{$board['id']}",
                            'permalink' => "https://www.wunderlist.com/#/tasks/{$task['id']}",
                            'desc' => '',
                            'starred' => $task['starred'],
                            'item_order' => (int)array_search($task['id'], $task_order_actual['values'], false),
                            'editable' => $this->itemsAreEditable(),
                            'completable' => true
                        ];

                        $final_json['cards'][] = $card_data;

                        if($today) {
                            $card_data['parent'] = "today-{$board['id']}";

                            $card_data['original_id'] = $card_data['uuid'];
                            $card_data['uuid'] = "today-{$card_data['uuid']}";

                            $final_json['cards'][] = $card_data;
                            $today_cards[$task['id']] = "today-{$board['id']}";
                        }

                        if($starred) {
                            $card_data['parent'] = "starred-{$board['id']}";

                            $card_data['original_id'] = $card_data['uuid'];
                            $card_data['uuid'] = "starred-{$card_data['uuid']}";

                            $final_json['cards'][] = $card_data;
                            $starred_cards[$task['id']] = "starred-{$board['id']}";
                        }

                    }
                } catch (\Exception $ex) {
                    if ($ex->getCode() != 404) {

                        $final_json['errors'][] = [
                            'message' => 'Error while syncing ' . self::TITLE,
                            'details' => $ex->getMessage()
                        ];
                    }

                }

            }

            $strategy = new ComplexSortStrategy();
            $strategy
                ->setSortOrder(Sorter::ASC)
                ->sortBy('item_order');

            $sorter = new Sorter();
            $final_json['cards'] = $sorter->setStrategy($strategy)->sort($final_json['cards']);

            $final_json['lists'] = array_values(array_filter($final_json['lists'], function($list) use ($today_cards) {
                if(strpos($list['uuid'], 'today-') === 0) {
                    return in_array($list['uuid'], $today_cards);
                }
                return true;
            }));


        return $final_json;
    }

    public function add(Application $app)
    {
        //$app['session']->set('csrf', );

        Auth2::legs(3)
            # configuration
            ->set(array(
                'id' => $this->key,
                'secret' => $this->secret,
                'redirect' => $app['url_generator']->generate('authorize_supplier', [], $app['url_generator']::ABSOLUTE_URL),
            ))
            # oauth flow
            ->authorize('https://www.wunderlist.com/oauth/authorize', ['state' => 'web_server', 'scope' => 'write']);

        return true;

    }

    public function authorize($token, $secret)
    {
        $oauth_token_info = [
            'oauth_token_secret' => ''
        ];
        Auth2::legs(3)
            # configuration
            ->set(array(
                'id' => $this->key,
                'secret' => $this->secret,
                'redirect' => 'http://my.pomodoneapp.com/supplier/auth/',
            ))
            # oauth flow
            ->authorize('https://www.wunderlist.com/oauth/authorize')
            ->access('https://www.wunderlist.com/oauth/access_token', ['scope' => 'write'])
            ->finally(function ($data) use (&$oauth_token_info) {
                $oauth_token_info['oauth_token'] = $data['access_token'];
            });
        return $oauth_token_info;
    }

    public function getContainers(array $service, $short_output = true)
    {

        $template_data = [];

        try {
            $client = new GuzzleHttpClient([
                'base_url' => 'https://a.wunderlist.com/api/v1/',
                'defaults' => [
                    'headers' => [
                        'X-Client-ID' => $this->key,
                        'X-Access-Token' => "{$service['oauth_token']}",
                    ]
                ]
            ]);


            $lists = $client->get('lists')->json();
        } catch (\Exception $ex) {
            $lists = $service['datasets'];
            $this->logger->addError($ex->getMessage(), $ex->getTrace());
        }
        $boards = [];

        if (!$short_output) {
            $selected_datasets = array_column($service['datasets'], 'sortIndex', 'id');
            $datasets_buckets = array_column($service['datasets'], 'accessLevel', 'id');

            $selected = array_column($service['datasets'], 'id');
            $projects = array_filter($lists, function ($b) use ($selected) {
                return in_array($b['id'], $selected);
            });

            array_walk($projects, function (&$project) use ($selected_datasets, $datasets_buckets) {
                $project = [
                    'id' => $project['id'],
                    'uuid' => $project['id'],
                    'source' => self::NAME,
                    'title' => $project['title'],
                    'sortIndex' => $selected_datasets[$project['id']],
                    'accessLevel' => $datasets_buckets[$project['id']],
                ];
            });
            return $projects;
        }


        foreach ($lists as $board) {
            $boards[] = [
                'id' => $board['id'],
                'title' => $board['title'],
                'accessLevel' => 0
            ];
        }

        if (array_key_exists('datasets', $service) and !empty($service['datasets'])) {
            $template_data['selected_datasets'] = $service['datasets'];

            $selected = array_column($service['datasets'], 'id');
            $boards = array_filter($boards, function ($b) use ($selected) {
                return !in_array($b['id'], $selected);
            });

        } else {
            $template_data['selected_datasets'][] = array_shift($boards);
        }


        $template_data['datasets'] = array_values($boards);
        $template_data['accounts'] = $service;


        return $template_data;
    }

    public function sendEvents(array $event_item, array $service)
    {
        $this->logger->addError("dumping id {$event_item['uuid']}", $event_item);

        $client = new GuzzleHttpClient([
            'base_url' => 'https://a.wunderlist.com/api/v1/',
            'defaults' => [
                'headers' => [
                    'X-Client-ID' => $this->key,
                    'X-Access-Token' => "{$service['oauth_token']}",
                ]
            ]
        ]);

        $task = $client->get("tasks/{$event_item['uuid']}")->json();

        switch ($event_item['action']) {
            case 'timerStart':
                $result = ['success' => true, 'message' => 'No available action for this provider'];
                break;
            case 'cardDone':
                $this->app['services.dashboard.remove']($event_item['uuid']);

                $do_sync_back = filter_var($service['settings']['sync_back'], FILTER_VALIDATE_BOOLEAN);

                if(!$do_sync_back && !empty($service['settings']['sync_back'])) {
                    $result = ['success' => true, 'message' => 'Sync back to service not enabled'];
                    break;
                }
                $status = $client->patch("tasks/{$event_item['uuid']}", ['json' => ['completed' => true, 'revision' => $task['revision']]])->getStatusCode();
                $result = ['success' => ($status == 200)];
                break;
            default:
                $result = ['success' => true];
                break;
        }

        $result += parent::sendEvents($event_item, $service);

        return $result;
    }

    public function storeLocalItem(Request $request)
    {
        $service = $this->getServiceAccount();

        $client = new GuzzleHttpClient([
            'base_url' => 'https://a.wunderlist.com/api/v1/',
            'defaults' => [
                'headers' => [
                    'X-Client-ID' => $this->key,
                    'X-Access-Token' => "{$service['oauth_token']}",
                ]
            ]
        ]);

        $task_payload = [
            'title' => $request->query->get('title', 'Untitled'),
            'completed' => false,
            'starred' => false,
        ];

        $project = $request->query->get('project');

        if($project === 'wunderlist-today') {
            $list = str_replace('today-', '', $request->query->get('parent'));
            $task_payload += [
                'list_id' => intval($list),
                'due_date' => date('Y-m-d')
            ];
        } else {
            $task_payload += [
                'list_id' => intval($project),
            ];
        }

        $raw_item = $client->post('tasks', ['json' => $task_payload])->json();

        $item = [
            'title' => $raw_item['title'],
            'source' => self::NAME,
            'uuid' => $raw_item['id'],
            'parent' => "tasks-{$raw_item['list_id']}",
            'permalink' => "https://www.wunderlist.com/#/tasks/{$raw_item['id']}",
            'desc' => '',
            'project' => $raw_item['list_id'],
            'editable' => $this->itemsAreEditable(),
            'completable' => true
        ];

        return ['success' => true, 'card' => $item];
    }

    public function editLocalItem(Request $request)
    {
        $service = $this->getServiceAccount();

        $client = new GuzzleHttpClient([
            'base_url' => 'https://a.wunderlist.com/api/v1/',
            'defaults' => [
                'headers' => [
                    'X-Client-ID' => $this->key,
                    'X-Access-Token' => "{$service['oauth_token']}",
                ]
            ]
        ]);

        $card_fields = $request->query->all();

        try {
            $raw_item = $client->get("tasks/{$card_fields['uuid']}")->json();

            $edit_payload = [
                'revision' => (int)$raw_item['revision'],
                'title' => $card_fields['title'],

            ];

            $project = $request->query->get('project');

            if($project === 'wunderlist-today') {
                $list = str_replace('today-', '', $request->query->get('parent'));
                $edit_payload += [
                    'list_id' => intval($list),
                    'due_date' => date('Y-m-d')
                ];
            } else {
                $edit_payload += [
                    'list_id' => (int)$card_fields['project']
                ];
            }

            $edited_item = $client->patch("tasks/{$card_fields['uuid']}", ['json' => $edit_payload])->json();

            $item = [
                'title' => $edited_item['title'],
                'source' => self::NAME,
                'uuid' => $raw_item['id'],
                'parent' => "tasks-{$card_fields['project']}",
                'permalink' => "https://www.wunderlist.com/#/tasks/{$raw_item['id']}",
                'desc' => '',
                'project' => $card_fields['project']
            ];

            return ['success' => true, 'card' => $item];
        } catch(\Exception $ex) {

            return ['success' => false, 'message' => self::TITLE . ' failed to store item changes'];

        }


    }

    public function getItemFromService($item_data, $service)
    {
        try {
            $client = new GuzzleHttpClient([
                'base_url' => 'https://a.wunderlist.com/api/v1/',
                'defaults' => [
                    'headers' => [
                        'X-Client-ID' => $this->key,
                        'X-Access-Token' => "{$service['oauth_token']}",
                    ]
                ]
            ]);

            $item = $client->get("tasks/{$item_data['id']}")->json();

            return [
                'id' => $item['id'],
                'source' => self::NAME,
                'project' => $item['list_id'],
                'list' => "tasks-{$item['list_id']}",
                'service_data' => [
                    'uuid' => $item['id'],
                    'title' => $item['title'],
                    'source' => self::NAME,
                    'parent' => "tasks-{$item['list_id']}",
                    'permalink' => "https://www.wunderlist.com/#/tasks/{$item['id']}",
                    'desc' => ''
                ],

            ];

        }
        catch(\Exception $ex) {
            return [];
        }
    }

    public function registerForServiceUpdates(array $service)
    {
        $account_id = (string)$service['_id'];

        $client = new GuzzleHttpClient([
            'base_url' => 'https://a.wunderlist.com/api/v1/',
            'defaults' => [
                'headers' => [
                    'X-Client-ID' => $this->key,
                    'X-Access-Token' => "{$service['oauth_token']}",
                ]
            ]
        ]);

        $requests = [];
        $responses = [
            'webhooks' => []
        ];
        $current_hooks = [];


        foreach($service['datasets'] as $idx => $container) {
            $requests[] = $client->createRequest('GET', 'webhooks',['query' => ['list_id' => $container['id']]]);
        }

        $batch = Pool::batch($client, $requests)->getSuccessful();

        foreach($batch as $resp) {
            try {
                $res = $resp->json();
                $current_hooks = array_merge($current_hooks, $res);
            } catch(\Exception $ex) {

            }
        }



        $requests = [];

        foreach($current_hooks as $sync_hook) {
            $requests[] = $client->createRequest('DELETE', "webhooks/{$sync_hook['id']}");
        }

        Pool::batch($client, $requests);

        $requests = [];

        foreach($service['datasets'] as $idx => $container) {
            $requests[] = $client->createRequest('POST', "webhooks",[
                'json' => [
                    'list_id' => $container['id'],
                    'url' => "https://api.pomodoneapp.com/sync/{$account_id}/",
                    'processor_type' => 'generic',
                    'configuration' => ''
                ]
            ]);
        }

        $batch = Pool::batch($client, $requests)->getSuccessful();

        foreach($batch as $resp) {
            try {
                $responses['webhooks'][] = $resp->json();
            } catch(\Exception $ex) {

            }
        }

        return $responses;
    }
}
