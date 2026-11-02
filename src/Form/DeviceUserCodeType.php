<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * User code field of the default device verification page (`user_code`, CSRF id
 * {@see self::CSRF_TOKEN_ID}). FormKit Type on the `time_track` profile (REQ-TWIG-005 rule 11).
 *
 * @extends AbstractType<array{user_code?: string|null}>
 */
#[FormKitConfig(TimerActionType::FORM_KIT_PROFILE)]
final class DeviceUserCodeType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'nowo_time_track_device/lookup';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fieldOptions = [
            'label'       => 'device.user_code',
            'help'        => 'device.user_code_help',
            'constraints' => [new NotBlank(), new Length(max: 16)],
            'attr'        => ['autocomplete' => 'one-time-code', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'maxlength' => 16, 'data-testid' => 'time-track-device-code'],
        ];

        if (!isset($this->formOptionsMerger)) {
            $builder->add('user_code', TextType::class, $fieldOptions);

            return;
        }

        $this->addWithDefaults($builder, 'user_code', TextType::class, $fieldOptions);
    }

    /**
     * CSRF id `nowo_time_track_device/lookup`, translation domain of the bundle.
     */
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'translation_domain' => 'NowoTimeTrackBundle',
        ]);
    }

    /**
     * Fields post as `device_code[user_code]`.
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return 'device_code';
    }
}
