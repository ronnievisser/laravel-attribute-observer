<?php

namespace AlexStewartJA\LaravelAttributeObserver;

use AlexStewartJA\LaravelAttributeObserver\Commands\LaravelAttributeObserverMakeCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelAttributeObserverServiceProvider extends PackageServiceProvider
{
    private const EVENTS = [
        'creating',
        'created',
        'updating',
        'updated',
        'saving',
        'saved',
        'deleting',
        'deleted',
    ];

    private const EVENT_REGEX = '/([A-Z]{1}\w[a-z]*$)/';

    private array $observers;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->observers = config('attribute-observer.observers', []);
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-attribute-observer')
            ->hasConfigFile()
            ->hasCommand(LaravelAttributeObserverMakeCommand::class);
    }

    public function boot()
    {
        parent::boot();
        $this->observeModels();
    }

    /**
     * This is where all the fun happens.
     */
    private function observeModels()
    {
        if (empty($this->observers)) {
            return;
        }

        foreach (array_keys($this->observers) as $modelClass) {
            if (! is_object($modelClass) && class_exists($modelClass)) {

                // Carry on if no attribute observers are defined for this model
                if (empty($this->observers[$modelClass])) {
                    continue;
                }

                foreach ($this->observers[$modelClass] as $observer) {
                    if (! is_object($observer) && class_exists($observer)) {
                        $observerInstance = App::make($observer);
                        $observerEventsAttribs = $this->parseObserverMethods($observerInstance);
                        $observedEvents = array_keys($observerEventsAttribs);

                        foreach ($observedEvents as $observedEvent) {
                            $modelClass::{$observedEvent}(function (Model $model) use ($observedEvent, $observerEventsAttribs, $observerInstance) {
                                if ($model->wasChanged()) {
                                    foreach ($observerEventsAttribs[$observedEvent] as $attribute) {
                                        if ($this->modelHasAttribute($model, $attribute) && $model->isDirty($attribute)) {
                                            $method = 'on' . Str::studly($attribute) . Str::ucfirst($observedEvent);
                                            $observerInstance->{$method}($model, $model->getAttributeValue($attribute), $model->getOriginal($attribute));
                                        }
                                    }
                                }
                            });
                        }
                    }
                }
            }
        }
    }

    /**
     * Scan an attribute observer, then parse and collate all 'legal' methods defined on it.
     *
     * @param mixed $observer_object_or_class Object or fully qualified class name as a string
     * @return array
     */
    private function parseObserverMethods($observer_object_or_class): array
    {
        $events_attribs_mapping = [];

        // Methods that react to attribute changes start with 'on'. Let's grab those...
        $observerMethods = array_map(
            function ($method) {
                return Str::startsWith($method, 'on') ? substr($method, 2) : false;
            },
            get_class_methods($observer_object_or_class)
        );

        foreach ($observerMethods as $observerMethod) {
            if ($observerMethod) {

                // The last capitalized word in the method's name is always the event being reacted to
                preg_match(self::EVENT_REGEX, $observerMethod, $matches);
                $event = strtolower($matches[1]);

                if (in_array($event, self::EVENTS)) {
                    $attribute = Str::snake(str_replace($matches[1], '', $observerMethod));

                    $events_attribs_mapping[$event][] = $attribute;
                }
            }
        }

        return $events_attribs_mapping;
    }

    /**
     * Comprehensively check for the presence of an attribute on a model instance
     *
     * @param Model $model
     * @param string $attribute
     * @return bool
     */
    private function modelHasAttribute(Model $model, string $attribute): bool
    {
        return ! method_exists($model, $attribute) &&
            (array_key_exists($attribute, $model->getAttributes()) ||
                array_key_exists($attribute, $model->getCasts()) ||
                $model->hasGetMutator($attribute) ||
                array_key_exists($attribute, $model->getRelations()));
    }
}
