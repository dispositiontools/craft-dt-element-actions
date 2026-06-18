<?php

namespace dispositiontools\dtelementactions;

use Craft;
use craft\base\Element;
use craft\base\ElementAction;
use craft\base\ElementActionInterface;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\helpers\Html;
use dispositiontools\dtelementactions\models\Settings;
use ReflectionMethod;
use yii\base\Event;

/**
 * ElementActions plugin
 *
 * @method static DtElementActions getInstance()
 * @method Settings getSettings()
 * @author Disposition Tools <support@disposition.tools>
 * @copyright Disposition Tools
 * @license https://craftcms.github.io/license/ Craft License
 */

class DtElementActions extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            // ...
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('dt-element-actions/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        foreach ([Entry::class, Category::class, Asset::class] as $elementType) {
            Event::on(
                $elementType,
                Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
                function(DefineHtmlEvent $event): void {
                    if ($event->static || !$event->sender instanceof ElementInterface) {
                        return;
                    }

                    $event->html .= $this->actionButtonsHtml($event->sender);
                }
            );
        }

        $productClass = self::productClass();

        if ($productClass !== null) {
            Event::on(
                $productClass,
                Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
                function(DefineHtmlEvent $event): void {
                    if ($event->static || !$event->sender instanceof ElementInterface) {
                        return;
                    }

                    $event->html .= $this->actionButtonsHtml($event->sender);
                }
            );

            Craft::$app->getView()->hook('cp.commerce.product.edit.details', function(array $context) use ($productClass): string {
                return isset($context['product']) && $context['product'] instanceof $productClass
                    ? $this->actionButtonsHtml($context['product'])
                    : '';
            });
        }
    }

    private function actionButtonsHtml(ElementInterface $element): string
    {
        if (!$element->id) {
            return '';
        }

        $elementType = get_class($element);
        $source = $this->sourceKey($element);

        if (!$source) {
            return '';
        }

        $actions = $this->actions($elementType, $source);

        $excludedActions = [
            'craft\elements\actions\Duplicate' => true,
            'craft\elements\actions\Restore' => true,
            'craft\elements\actions\Delete' => true,
            'craft\elements\actions\DeleteForSite' => true,
        ];

        // todo: we need to grab from a config file any other actions that should be excluded


        $actions = array_filter(
            $actions,
            static fn(ElementActionInterface $action): bool => !isset($excludedActions[$action::class])
        );

        if (empty($actions)) {
            return '';
        }

        $items = [];

        foreach ($actions as $action) {
            $classes = ['dt-element-actions__action'];

            if ($action::isDestructive()) {
                $classes[] = 'error';
            }

            $items[] = Html::tag('li',
                Html::a(Html::encode($action->getTriggerLabel()), '#', [
                    'class' => $classes,
                    'data-action-class' => get_class($action),
                    'data-confirm' => $action->getConfirmationMessage(),
                ])
            );
        }

        $this->registerActionButtonsJs($element, $elementType, $source);

        return Html::tag('div',
            Html::button(Craft::t('dt-element-actions', 'Element actions'), [
                'type' => 'button',
                'class' => ['btn', 'menubtn'],
                'data-icon' => 'settings',
            ]) .
            Html::tag('div',
                Html::tag('ul', implode('', $items), ['class' => 'padded']),
                ['class' => 'menu']
            ),
            ['class' => ['btngroup', 'dt-element-actions']]
        );
    }

    /**
     * @param class-string<ElementInterface> $elementType
     * @return ElementActionInterface[]
     */
    private function actions(string $elementType, string $source): array
    {
        $actions = [];

        foreach ($elementType::actions($source) as $action) {
            if ($action instanceof ElementActionInterface) {
                $action->setElementType($elementType);
            } else {
                $config = is_string($action) ? ['type' => $action] : $action;
                $config['elementType'] = $elementType;
                $action = Craft::$app->getElements()->createAction($config);
            }

            $method = new ReflectionMethod($action, 'performAction');

            if (
                $method->getDeclaringClass()->getName() !== ElementAction::class &&
                !$action::isDownload() &&
                $action->validate()
            ) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    private function sourceKey(ElementInterface $element): ?string
    {

      
        if ($element instanceof Entry) {
            return $element->getSection() ? 'section:' . $element->getSection()->uid : null;
        }

        if ($element instanceof Category) {
            return $element->getGroup() ? 'group:' . $element->getGroup()->uid : null;
        }

        if ($element instanceof Asset) {
            return $element->getVolume() ? 'volume:' . $element->getVolume()->uid : null;
        }

        $productClass = self::productClass();

        if ($productClass !== null && $element instanceof $productClass) {
            return $element->getType() ? 'productType:' . $element->getType()->uid : null;
        }

        return null;
    }

    private static function productClass(): ?string
    {
        return class_exists('craft\\commerce\\elements\\Product')
            ? 'craft\\commerce\\elements\\Product'
            : null;
    }

    private function registerActionButtonsJs(ElementInterface $element, string $elementType, string $source): void
    {
        Craft::$app->getView()->registerJsWithVars(fn($elementType, $source, $elementId, $siteId) => <<<JS
(() => {
    $(document)
        .off('click.dtElementActions')
        .on('click.dtElementActions', '.dt-element-actions__action', (event) => {
            event.preventDefault();

            const \$trigger = $(event.currentTarget);
            const confirmation = \$trigger.data('confirm');

            if (confirmation && !confirm(confirmation)) {
                return;
            }

            Craft.sendActionRequest('POST', 'element-indexes/perform-action', {
                data: {
                    elementType: $elementType,
                    source: $source,
                    elementAction: \$trigger.data('action-class'),
                    elementIds: [$elementId],
                    criteria: {
                        siteId: $siteId,
                    },
                },
            }).then((response) => {
                if (response.data.message) {
                    Craft.cp.displaySuccess(response.data.message);
                }
            }).catch((error) => {
                Craft.cp.displayError(error?.response?.data?.message);
            });
        });
})();
JS, [
            $elementType,
            $source,
            $element->id,
            $element->siteId ?? null,
        ]);
    }
}
