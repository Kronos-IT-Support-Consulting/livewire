<?php

namespace Livewire\Features\SupportEvents;

use function Livewire\wrap;
use function Livewire\store;
use function Livewire\invade;
use Livewire\Features\SupportAttributes\AttributeLevel;
use Livewire\ComponentHook;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Mechanisms\HandleComponents\BaseRenderless;

class SupportEvents extends ComponentHook
{
    function call($method, $params, $returnEarly)
    {
        if ($method === '__dispatch') {
            /// It's a lazy component that has just being hydrated
//            if (store($this->component)->get('isLazyLoadHydrating')) {
//                $hook = new SupportLazyLoading;
//
//                $hook->setComponent($this->component);
//
//                $hook->call('__lazyLoad', $params, $returnEarly);
//            }

            [$name, $params] = $params;

            $names = static::getListenerEventNames($this->component);

            if (! in_array($name, $names)) {
                throw new EventHandlerDoesNotExist($name);
            }

            $method = static::getListenerMethodName($this->component, $name);

            $returnEarly(
                wrap($this->component)->$method(...$params)
            );

            // Here we have to manually check to see if the event listener method
            // is "renderless" as it's normal "call" hook doesn't get run when
            // the method is called as an event listener...
            $isRenderless = $this->component->getAttributes()
                    ->filter(fn ($i) => is_subclass_of($i, BaseRenderless::class))
                    ->filter(fn ($i) => $i->getName() === $method)
                    ->filter(fn ($i) => $i->getLevel() === AttributeLevel::METHOD)
                    ->count() > 0;

            if ($isRenderless) $this->component->skipRender();
        }
    }

    function dehydrate($context)
    {
        if ($context->mounting) {
            $listeners = static::getListenerEventNames($this->component);
            $isLazyLoadMounting = store($this->component)->get('isLazyLoadMounting') === true;

            if ($isLazyLoadMounting) {
                $lazyListening = store($this->component)->get('lazyListening') === true;

                $listeners && $lazyListening && $context->addEffect('listeners', $listeners);
            } else {
                $listeners && $context->addEffect('listeners', $listeners);
            }
        } else {
            $isLazyLoadHydrating = store($this->component)->get('isLazyLoadHydrating') === true;
            $lazyListening = store($this->component)->get('lazyListening') === true;

            // Add listener effects when lazy-loaded components are mounting
            if ($isLazyLoadHydrating && ! $lazyListening) {
                $listeners = static::getListenerEventNames($this->component);

                $listeners && $context->addEffect('listeners', $listeners);
            }
        }

        $dispatches = $this->getServerDispatchedEvents($this->component);

        $dispatches && $context->addEffect('dispatches', $dispatches);
    }

    static function getListenerEventNames($component)
    {
        $listeners = static::getComponentListeners($component);

        return collect($listeners)
            ->map(fn ($value, $key) => is_numeric($key) ? $value : $key)
            ->values()
            ->toArray();
    }

    static function getListenerMethodName($component, $name)
    {
        $listeners = static::getComponentListeners($component);

        foreach ($listeners as $event => $method) {
            if (is_numeric($event)) $event = $method;

            if ($name === $event) return $method;
        }

        throw new \Exception('Event method not found');
    }

    static function getComponentListeners($component)
    {
        $fromClass = invade($component)->getListeners();

        $fromAttributes = store($component)->get('listenersFromAttributes', []);

        $listeners = array_merge($fromClass, $fromAttributes);

        return static::replaceDynamicEventNamePlaceholders($listeners, $component);
    }

    function getServerDispatchedEvents($component)
    {
        return collect(store($component)->get('dispatched', []))
            ->map(fn ($event) => $event->serialize())
            ->toArray();
    }

    static function replaceDynamicEventNamePlaceholders($listeners, $component)
    {
        foreach ($listeners as $event => $method) {
            if (is_numeric($event)) continue;

            $replaced = static::replaceDynamicPlaceholders($event, $component);

            unset($listeners[$event]);

            $listeners[$replaced] = $method;
        }

        return $listeners;
    }

    static function replaceDynamicPlaceholders($event, $component)
    {
        return preg_replace_callback('/\{(.*)\}/U', function ($matches) use ($component) {
            return data_get($component, $matches[1], function () use ($matches) {
                throw new \Exception('Unable to evaluate dynamic event name placeholder: '.$matches[0]);
            });
        }, $event);
    }
}
