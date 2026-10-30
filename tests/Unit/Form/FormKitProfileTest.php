<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\DependencyInjection\Configuration as FormKitConfiguration;
use Nowo\FormKitBundle\DependencyInjection\FormKitExtension;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Form\TimerActionType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;

/**
 * REQ-TWIG-005 rule 11: the timer action form is a FormKit Type on the `time_track` profile, and the stock profile
 * renders it exactly as before.
 */
final class FormKitProfileTest extends TestCase
{
    public function testTimerActionUsesTheTimeTrackProfileAndRendersTheSameFields(): void
    {
        $type = new TimerActionType();
        self::assertSame(TimerActionType::FORM_KIT_PROFILE, FormKitConfig::nameFrom($type));

        $plain = $this->view(Forms::createFormFactory());

        /** @var array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>, help_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>} $config */
        $config = TimeTrackExtension::formKitProfile() + ['defaults' => ['attr' => [], 'row_attr' => [], 'help_attr' => []], 'field_types' => []];
        $type->setFormOptionsMerger(new FormOptionsMerger(
            [TimerActionType::FORM_KIT_PROFILE => $config],
            TimerActionType::FORM_KIT_PROFILE,
            new ConstraintDefinitionFactory(),
        ));
        $kit = $this->view(Forms::createFormFactoryBuilder()->addType($type)->getFormFactory());

        self::assertSame(array_keys($plain->children), array_keys($kit->children));
        foreach ($plain->children as $name => $child) {
            foreach (['id', 'full_name', 'value', 'attr', 'row_attr', 'required'] as $var) {
                self::assertSame($child->vars[$var] ?? null, $kit->children[$name]->vars[$var] ?? null, $name . '.' . $var);
            }
        }
    }

    public function testPrependSkipsHostsWithoutFormKit(): void
    {
        $container = new ContainerBuilder();
        (new TimeTrackExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_form_kit'));
    }

    public function testPrependSeedsTheProfileAndKeepsTheImplicitDefaultProfile(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        (new TimeTrackExtension())->prepend($container);

        $config = (new Processor())->processConfiguration(new FormKitConfiguration(), $container->getExtensionConfig('nowo_form_kit'));

        self::assertSame('default', $config['default_profile']);
        self::assertArrayHasKey('default', $config['profiles']);
        self::assertSame('NowoTimeTrackBundle', $config['profiles']['time_track']['translation_domain']);
        self::assertFalse($config['profiles']['time_track']['auto_help']);
        self::assertFalse($config['profiles']['time_track']['auto_placeholder']);
    }

    public function testPrependAddsTheProfileNextToTheHostConfig(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        $container->loadFromExtension('nowo_form_kit', ['default_profile' => 'app', 'profiles' => ['app' => ['alias' => 'app']]]);
        (new TimeTrackExtension())->prepend($container);

        $config = (new Processor())->processConfiguration(new FormKitConfiguration(), $container->getExtensionConfig('nowo_form_kit'));

        self::assertSame('app', $config['default_profile']);
        self::assertArrayNotHasKey('default', $config['profiles']);
        self::assertArrayHasKey('time_track', $config['profiles']);
    }

    public function testPrependKeepsAHostDefinedProfile(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        $container->loadFromExtension('nowo_form_kit', ['profiles' => ['time_track' => ['alias' => 'time_track', 'translation_domain' => 'admin']]]);
        (new TimeTrackExtension())->prepend($container);

        self::assertCount(1, $container->getExtensionConfig('nowo_form_kit'));
    }

    private function view(FormFactoryInterface $factory): FormView
    {
        return $factory->createNamed('', TimerActionType::class, ['action' => 'start', 'task_id' => 't1'], ['csrf_protection' => false, 'id_suffix' => 'row'])->createView();
    }
}
