<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Form;

use Nowo\TimeTrackBundle\Form\TimerActionType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

use function array_map;

final class TimerActionTypeTest extends TestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        $this->factory = Forms::createFormFactory();
    }

    public function testFieldNamesStayFlat(): void
    {
        $view = $this->factory->createNamed('', TimerActionType::class, ['action' => 'start', 'task_id' => 'task-1'])->createView();

        self::assertSame('', (new TimerActionType())->getBlockPrefix());
        self::assertSame('action', $view->children['action']->vars['full_name']);
        self::assertSame('task_id', $view->children['task_id']->vars['full_name']);
        self::assertSame('action', $view->children['action']->vars['id']);
        self::assertSame('task-1', $view->children['task_id']->vars['value']);
        self::assertSame('POST', $view->vars['method']);
    }

    public function testIdSuffixMakesRowIdsUnique(): void
    {
        $view = $this->factory->createNamed('', TimerActionType::class, null, ['id_suffix' => 'start_task-2'])->createView();

        self::assertSame('action_start_task-2', $view->children['action']->vars['id']);
        self::assertSame('task_id_start_task-2', $view->children['task_id']->vars['id']);
    }

    public function testSubmitReturnsPostedData(): void
    {
        $form = $this->factory->createNamed('', TimerActionType::class);
        $form->submit(['action' => 'stop', 'task_id' => '']);

        self::assertTrue($form->isSynchronized());
        self::assertSame(['action' => 'stop', 'task_id' => null], $form->getData());
    }

    /**
     * Since 2.1.0 (audit ZBQ-L4): `activity_types` (label => key) adds a required, flat `activity_type` choice; none
     * without choices.
     */
    public function testActivityTypesAddARequiredChoice(): void
    {
        $form = $this->factory->createNamed('', TimerActionType::class, ['action' => 'stop'], ['activity_types' => ['Development (dev)' => 'dev', 'Support (support)' => 'support'], 'id_suffix' => 'stop']);
        $view = $form->createView();

        self::assertSame(['activity_type', 'activity_type_stop', true, 'stop.activity_type', 'stop.activity_type_placeholder'], [$view->children['activity_type']->vars['full_name'], $view->children['activity_type']->vars['id'], $view->children['activity_type']->vars['required'], $view->children['activity_type']->vars['label'], $view->children['activity_type']->vars['placeholder']]);
        self::assertSame(['dev', 'support'], array_map(static fn (ChoiceView $choice): string => $choice->value, $view->children['activity_type']->vars['choices']));

        $form->submit(['action' => 'stop', 'task_id' => '', 'activity_type' => 'support']);
        self::assertSame(['action' => 'stop', 'task_id' => null, 'activity_type' => 'support'], $form->getData());

        $unknown = $this->factory->createNamed('', TimerActionType::class, null, ['activity_types' => ['Development (dev)' => 'dev']]);
        $unknown->submit(['action' => 'stop', 'activity_type' => 'legacy']);
        self::assertFalse($unknown->get('activity_type')->isSynchronized(), 'a key it does not offer');

        self::assertFalse($this->factory->createNamed('', TimerActionType::class, null, ['activity_types' => []])->has('activity_type'));
    }

    public function testCsrfDefaultsMatchControllerContract(): void
    {
        $form    = $this->factory->createNamed('', TimerActionType::class);
        $options = $form->getConfig()->getOptions();

        self::assertSame('_token', $options['csrf_field_name']);
        self::assertSame('nowo_time_track_manage', $options['csrf_token_id']);
    }
}
