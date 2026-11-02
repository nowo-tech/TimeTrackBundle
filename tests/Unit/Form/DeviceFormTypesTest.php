<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Form;

use Nowo\TimeTrackBundle\Form\DeviceDecisionType;
use Nowo\TimeTrackBundle\Form\DeviceUserCodeType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * Device forms without FormKit (plain form factory): flat decision fields with per-action ids, the code field.
 */
final class DeviceFormTypesTest extends TestCase
{
    public function testDecisionFormPostsFlatFieldsWithTheBoundCsrfId(): void
    {
        $form = Forms::createFormFactory()->createNamed('', DeviceDecisionType::class, ['decision' => 'approve', 'request' => 'r-1'], ['csrf_token_id' => 'nowo_time_track_device/approve/r-1', 'id_suffix' => 'approve']);
        $view = $form->createView();

        self::assertSame('decision', $view->children['decision']->vars['full_name']);
        self::assertSame('request_approve', $view->children['request']->vars['id']);
        self::assertSame('nowo_time_track_device/approve/r-1', $form->getConfig()->getOption('csrf_token_id'));
        self::assertSame('', (new DeviceDecisionType())->getBlockPrefix());
    }

    public function testUserCodeForm(): void
    {
        $form = Forms::createFormFactoryBuilder()->addExtension(new ValidatorExtension(Validation::createValidator()))->getFormFactory()->create(DeviceUserCodeType::class, ['user_code' => 'BCDF-GHJK']);
        $view = $form->createView();

        self::assertSame('device_code[user_code]', $view->children['user_code']->vars['full_name']);
        self::assertSame('nowo_time_track_device/lookup', $form->getConfig()->getOption('csrf_token_id'));
    }
}
