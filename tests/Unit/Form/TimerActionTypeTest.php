<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Form;

use Nowo\TimeTrackBundle\Form\TimerActionType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

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

    public function testCsrfDefaultsMatchControllerContract(): void
    {
        $form    = $this->factory->createNamed('', TimerActionType::class);
        $options = $form->getConfig()->getOptions();

        self::assertSame('_token', $options['csrf_field_name']);
        self::assertSame('nowo_time_track_manage', $options['csrf_token_id']);
    }
}
