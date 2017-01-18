<?php

namespace Pomodone\Providers;


use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class BaseProvider implements ServiceProviderInterface {

    const NAME = '';
    const TITLE = '';

    protected $key = '';
    protected $secret = '';
    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Logger
     */
    protected $logger;

    public $features = [
        'items' => 'itemsFromSelectedContainers',
        'containers' => 'getContainers',
        'add' => 'add',
        'authorize' => 'authorize'
    ];

    public function __construct(array $configuration)
    {
        $this->key = $configuration['key'];
        $this->secret = $configuration['secret'];
    }

    public function getTitle()
    {
        return $this::TITLE;
    }

    protected function getSort(array $service)
    {
        return array_key_exists('sortIndex', $service) ? $service['sortIndex'] : 99;
    }

    /**
     * Registers services on the given app.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     *
     * @param Application $app An Application instance
     */
    public function register(Application $app)
    {
        $this->app = $app;

        $app["services.".$this::NAME.".add"] = $app->protect(function() use ($app) {
            return $this->add($app);
        });

        $app["services.".$this::NAME.".authorize"] = $app->protect(function($token, $secret) use ($app) {
            return $this->authorize($token, $secret);
        });

        $app["services.".$this::NAME.".items"] = $app->protect(function($service, $filters = []) use ($app) {
            return $this->itemsFromSelectedContainers($service, $filters);
        });

        $app["services.".$this::NAME.".containers"] = $app->protect(function($service, $short_output = true) use ($app) {

            /*if (!array_key_exists('datasets', $service) or empty($service['datasets'])) {
                return [
                    'projects' => [],
                ];
            }*/

            return $this->getContainers($service, $short_output);
        });

        $app["services.".$this::NAME.".sync_registration"] = $app->protect(function($service) use ($app) {
            return $this->registerForServiceUpdates($service);
        });

        $app["services.".$this::NAME.".send_events"] = $app->protect(function($event, $service) use ($app) {
            return $app->json($this->sendEvents($event, $service))->setCallback($app['request']->get('callback'));
        });

        $app["services.".$this::NAME.".sync"] = $app->protect(function(Request $request, $service) use ($app) {
            $app['monolog']->addDebug($request->getContent());
            return $this->sync($request, $service);
        });

        $app["services.".$this::NAME.".store"] = $app->protect(function(Request $request) use ($app) {
            return $this->storeLocalItem($request);
        });

        $app["services.".$this::NAME.".edit"] = $app->protect(function(Request $request) use ($app) {
            return $this->editLocalItem($request);
        });

        $app["services.".$this::NAME.".remove"] = $app->protect(function($request) use ($app) {
            return $this->removeItem($request);
        });

        $app["services.".$this::NAME.".title"] = $app->protect(function() {
            return $this->getTitle();
        });

        $app["services.".$this::NAME.".get_service_item"] = $app->protect(function($item_data, $service) {
            return $this->getItemFromService($item_data, $service);
        });

        //Set up per service logging
        $app["monolog.".$this::NAME.".handler"] = $app->share(function ($app) {
                return new StreamHandler(__DIR__ . "/../../../resources/log/".$this::NAME.".log", Logger::DEBUG);
        });
    }

    /**
     * Bootstraps the application.
     *
     * This method is called after all services are registered
     * and should be used for "dynamic" configuration (whenever
     * a service must be requested).
     * @param Application $app An Application instance
     */
    public function boot(Application $app)
    {
        $app['monolog.'.$this::NAME] = $app->share(function ($app) {
            $log = new $app['monolog.logger.class']($this::NAME);
            $log->pushHandler($app["monolog.".$this::NAME.".handler"]);

            return $log;
        });

        $this->logger = $app['monolog.'.$this::NAME];
    }

    /**
     * @param array $service
     * @param array $filters
     * @return array
     */
    abstract public function itemsFromSelectedContainers(array $service, array $filters = []);

    /**
     * @param Application $app
     * @return RedirectResponse
     */
    abstract public function add(Application $app);

    /**
     * @param $token
     * @param $secret
     * @return array
     */
    abstract public function authorize($token, $secret);

    /**
     * @param array $service
     * @param bool $short_output
     * @return array
     */
    abstract public function getContainers(array $service, $short_output = true);

    public function sendEvents(array $events, array $service) {


        /*switch($events['action']) {
            case 'timerStart':
                $r->sAdd("Timers:{$service['uid']}","Timer:{$service['uid']}:{$events['startTimeStamp']}");
                $r->setEx("Timer:{$service['uid']}:{$events['startTimeStamp']}", (int)$events['timer']['duration'], json_encode($events));

                break;
            case 'timerStop':
                $r->sRem("Timers:{$service['uid']}","Timer:{$service['uid']}:{$events['startTimeStamp']}");
                $r->del("Timer:{$service['uid']}:{$events['startTimeStamp']}");
                break;
        }*/

        switch($events['action']) {
            case 'cardDone':
                //$this->app["services.dashboard.remove"]($events['uuid']);
                break;
        }

        switch($events['action']) {
            case 'timerStart':
            case 'timerStop':
            case 'cardDone':
                break;
        }

        return ['success' => true, 'message' => 'Event sync is not yet implemented for this provider'];
    }

    public function registerForServiceUpdates(array $service) {
        return ['success' => true, 'message' => 'Server side data updates are not yet implemented for this provider'];
    }

    public function storeLocalItem(Request $request) {
        return ['success' => false, 'message' => 'Item creation is not yet implemented for this provider'];
    }

    public function editLocalItem(Request $request) {
        return ['success' => false, 'message' => 'Item editing is not yet implemented for this provider'];
    }

    public function removeItem($request) {
        return ['success' => false, 'message' => 'Item removal is not yet implemented for this provider'];
    }

    public function getItemFromService($item_data, $service) {
        return ['success' => false, 'message' => 'Single item fetching is not yet implemented for this provider'];
    }

    public function sync(Request $request, $service) {
        return [];
    }

    public function getServiceAccount() {



        return [];
    }

    public function updateServiceAccount(array $fields) {


        return [];
    }

    public function itemsAreEditable()
    {
        $reflector = new \ReflectionClass($this);
        
        $methods = $reflector->getMethods();

        $own_methods = [];

        $name = get_class($this);

        foreach($methods as $method_definition) {
            if($method_definition->getDeclaringClass()->getName() == $name) {
                $own_methods[] = $method_definition->getName();
            }
        }

        return in_array('editLocalItem', $own_methods);
    }

    public function canCreateNew()
    {
        $reflector = new \ReflectionClass($this);

        $methods = $reflector->getMethods();

        $own_methods = [];

        $name = get_class($this);

        foreach($methods as $method_definition) {
            if($method_definition->getDeclaringClass()->getName() == $name) {
                $own_methods[] = $method_definition->getName();
            }
        }

        return in_array('storeLocalItem', $own_methods);
    }

    public static function convertLinked(array $event_item)
    {
        if(array_key_exists('card', $event_item) && array_key_exists('originalCard', $event_item['card'])) {
            $event_item['card'] = $event_item['card']['originalCard'];
            $event_item['source'] = $event_item['card']['source'];
            $event_item['uuid'] = $event_item['card']['uuid'];
        }
        return $event_item;
    }
}
