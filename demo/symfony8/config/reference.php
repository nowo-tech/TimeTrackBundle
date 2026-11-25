<?php

// This file is auto-generated and is for apps only. Bundles SHOULD NOT rely on its content.

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Config\Loader\ParamConfigurator as Param;

/**
 * This class provides array-shapes for configuring the services and bundles of an application.
 *
 * Services declared with the config() method below are autowired and autoconfigured by default.
 *
 * This is for apps only. Bundles SHOULD NOT use it.
 *
 * Example:
 *
 *     ```php
 *     // config/services.php
 *     namespace Symfony\Component\DependencyInjection\Loader\Configurator;
 *
 *     return App::config([
 *         'services' => [
 *             'App\\' => [
 *                 'resource' => '../src/',
 *             ],
 *         ],
 *     ]);
 *     ```
 *
 * @psalm-type ImportsConfig = list<string|array{
 *     resource: string,
 *     type?: string|null,
 *     ignore_errors?: bool|'not_found',
 * }>
 * @psalm-type ParametersConfig = array<string, scalar|\UnitEnum|array<scalar|\UnitEnum|array<mixed>|Param|null>|Param|null>
 * @psalm-type ArgumentsType = list<mixed>|array<string, mixed>
 * @psalm-type CallType = array<string, ArgumentsType>|array{0:string, 1?:ArgumentsType, 2?:bool}|array{method:string, arguments?:ArgumentsType, returns_clone?:bool}
 * @psalm-type TagsType = list<string|array<string, array<string, mixed>>> // arrays inside the list must have only one element, with the tag name as the key
 * @psalm-type CallbackType = string|array{0:string|ReferenceConfigurator,1:string}|\Closure|ReferenceConfigurator
 * @psalm-type DeprecationType = array{package: string, version: string, message?: string}
 * @psalm-type DefaultsType = array{
 *     public?: bool,
 *     tags?: TagsType,
 *     resource_tags?: TagsType,
 *     autowire?: bool,
 *     autoconfigure?: bool,
 *     bind?: array<string, mixed>,
 * }
 * @psalm-type InstanceofType = array{
 *     shared?: bool,
 *     lazy?: bool|string,
 *     public?: bool,
 *     properties?: array<string, mixed>,
 *     configurator?: CallbackType,
 *     calls?: list<CallType>,
 *     tags?: TagsType,
 *     resource_tags?: TagsType,
 *     autowire?: bool,
 *     bind?: array<string, mixed>,
 *     constructor?: string,
 * }
 * @psalm-type DefinitionType = array{
 *     class?: string,
 *     file?: string,
 *     parent?: string,
 *     shared?: bool,
 *     synthetic?: bool,
 *     lazy?: bool|string,
 *     public?: bool,
 *     abstract?: bool,
 *     deprecated?: DeprecationType,
 *     factory?: CallbackType,
 *     configurator?: CallbackType,
 *     arguments?: ArgumentsType,
 *     properties?: array<string, mixed>,
 *     calls?: list<CallType>,
 *     tags?: TagsType,
 *     resource_tags?: TagsType,
 *     decorates?: string,
 *     decorates_tag?: string,
 *     decoration_inner_name?: string,
 *     decoration_priority?: int,
 *     decoration_on_invalid?: 'exception'|'ignore'|null,
 *     autowire?: bool,
 *     autoconfigure?: bool,
 *     bind?: array<string, mixed>,
 *     constructor?: string,
 *     from_callable?: CallbackType,
 * }
 * @psalm-type AliasType = string|array{
 *     alias: string,
 *     public?: bool,
 *     deprecated?: DeprecationType,
 * }
 * @psalm-type PrototypeType = array{
 *     resource: string,
 *     namespace?: string,
 *     exclude?: string|list<string>,
 *     parent?: string,
 *     shared?: bool,
 *     lazy?: bool|string,
 *     public?: bool,
 *     abstract?: bool,
 *     deprecated?: DeprecationType,
 *     factory?: CallbackType,
 *     arguments?: ArgumentsType,
 *     properties?: array<string, mixed>,
 *     configurator?: CallbackType,
 *     calls?: list<CallType>,
 *     tags?: TagsType,
 *     resource_tags?: TagsType,
 *     autowire?: bool,
 *     autoconfigure?: bool,
 *     bind?: array<string, mixed>,
 *     constructor?: string,
 * }
 * @psalm-type StackType = array{
 *     stack: list<DefinitionType|AliasType|PrototypeType|array<class-string, ArgumentsType|null>>,
 *     public?: bool,
 *     deprecated?: DeprecationType,
 *     decorates?: string,
 *     decorates_tag?: string,
 *     decoration_inner_name?: string,
 *     decoration_priority?: int,
 *     decoration_on_invalid?: 'exception'|'ignore'|null,
 * }
 * @psalm-type ServicesConfig = array{
 *     _defaults?: DefaultsType,
 *     _instanceof?: array<class-string, InstanceofType>,
 *     ...<string, DefinitionType|AliasType|PrototypeType|StackType|ArgumentsType|null>
 * }
 * @psalm-type ExtensionType = array<string, mixed>
 * @psalm-type FrameworkConfig = array{
 *     secret?: scalar|Param|null,
 *     http_method_override?: bool|Param, // Set true to enable support for the '_method' request parameter to determine the intended HTTP method on POST requests. // Default: false
 *     allowed_http_method_override?: null|list<string|Param>,
 *     trust_x_sendfile_type_header?: scalar|Param|null, // Set true to enable support for xsendfile in binary file responses. // Default: "%env(bool:default::SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER)%"
 *     ide?: scalar|Param|null, // Default: "%env(default::SYMFONY_IDE)%"
 *     test?: bool|Param,
 *     default_locale?: scalar|Param|null, // Default: "en"
 *     set_locale_from_accept_language?: bool|Param, // Whether to use the Accept-Language HTTP header to set the Request locale (only when the "_locale" request attribute is not passed). // Default: false
 *     set_content_language_from_locale?: bool|Param, // Whether to set the Content-Language HTTP header on the Response using the Request locale. // Default: false
 *     enabled_locales?: list<scalar|Param|null>,
 *     trusted_hosts?: Param|string|list<scalar|Param|null>,
 *     trusted_proxies?: mixed, // Default: ["%env(default::SYMFONY_TRUSTED_PROXIES)%"]
 *     trusted_headers?: Param|string|list<scalar|Param|null>,
 *     error_controller?: scalar|Param|null, // Default: "error_controller"
 *     handle_all_throwables?: bool|Param, // HttpKernel will handle all kinds of \Throwable. // Default: true
 *     csrf_protection?: bool|array{
 *         enabled?: scalar|Param|null, // Default: null
 *         stateless_token_ids?: list<scalar|Param|null>,
 *         check_header?: scalar|Param|null, // Whether to check the CSRF token in a header in addition to a cookie when using stateless protection. // Default: false
 *         cookie_name?: scalar|Param|null, // The name of the cookie to use when using stateless protection. // Default: "csrf-token"
 *     },
 *     form?: bool|array{ // Form configuration
 *         enabled?: bool|Param, // Default: true
 *         csrf_protection?: bool|array{
 *             enabled?: scalar|Param|null, // Default: null
 *             token_id?: scalar|Param|null, // Default: null
 *             field_name?: scalar|Param|null, // Default: "_token"
 *             field_attr?: array<string, scalar|Param|null>,
 *         },
 *     },
 *     http_cache?: bool|array{ // HTTP cache configuration
 *         enabled?: bool|Param, // Default: false
 *         debug?: bool|Param, // Default: "%kernel.debug%"
 *         trace_level?: "none"|"short"|"full"|Param,
 *         trace_header?: scalar|Param|null,
 *         default_ttl?: int|Param,
 *         private_headers?: list<scalar|Param|null>,
 *         skip_response_headers?: list<scalar|Param|null>,
 *         allow_reload?: bool|Param,
 *         allow_revalidate?: bool|Param,
 *         stale_while_revalidate?: int|Param,
 *         stale_if_error?: int|Param,
 *         terminate_on_cache_hit?: bool|Param, // Deprecated: Setting the "framework.http_cache.terminate_on_cache_hit.terminate_on_cache_hit" configuration option is deprecated. It will be removed in version 9.0.
 *     },
 *     esi?: bool|array{ // ESI configuration
 *         enabled?: bool|Param, // Default: false
 *     },
 *     ssi?: bool|array{ // SSI configuration
 *         enabled?: bool|Param, // Default: false
 *     },
 *     fragments?: bool|array{ // Fragments configuration
 *         enabled?: bool|Param, // Default: false
 *         hinclude_default_template?: scalar|Param|null, // Default: null
 *         path?: scalar|Param|null, // Default: "/_fragment"
 *     },
 *     profiler?: bool|array{ // Profiler configuration
 *         enabled?: bool|Param, // Default: false
 *         collect?: bool|Param, // Default: true
 *         collect_parameter?: scalar|Param|null, // The name of the parameter to use to enable or disable collection on a per request basis. // Default: null
 *         only_exceptions?: bool|Param, // Default: false
 *         only_main_requests?: bool|Param, // Default: false
 *         dsn?: scalar|Param|null, // Default: "file:%kernel.cache_dir%/profiler"
 *         collect_serializer_data?: true|Param, // Deprecated: Setting the "framework.profiler.collect_serializer_data.collect_serializer_data" configuration option is deprecated. It will be removed in version 9.0. // Default: true
 *     },
 *     workflows?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *         workflows?: array<string, array{ // Default: []
 *             audit_trail?: bool|array{
 *                 enabled?: bool|Param, // Default: false
 *             },
 *             type?: "workflow"|"state_machine"|Param, // Default: "state_machine"
 *             marking_store?: array{
 *                 type?: "method"|Param,
 *                 property?: scalar|Param|null,
 *                 service?: scalar|Param|null,
 *             },
 *             supports?: Param|string|list<scalar|Param|null>,
 *             definition_validators?: list<scalar|Param|null>,
 *             support_strategy?: scalar|Param|null,
 *             initial_marking?: \BackedEnum|Param|string|list<scalar|Param|null>,
 *             events_to_dispatch?: null|list<string|Param>,
 *             places?: Param|string|list<array{ // Default: []
 *                 name?: scalar|Param|null,
 *                 metadata?: array<string, mixed>,
 *             }>,
 *             transitions?: list<array{ // Default: []
 *                 name?: string|Param,
 *                 guard?: string|Param, // An expression to block the transition.
 *                 from?: \BackedEnum|Param|string|list<array{ // Default: []
 *                     place?: string|Param,
 *                     weight?: int|Param, // Default: 1
 *                 }>,
 *                 to?: \BackedEnum|Param|string|list<array{ // Default: []
 *                     place?: string|Param,
 *                     weight?: int|Param, // Default: 1
 *                 }>,
 *                 weight?: int|Param, // Default: 1
 *                 metadata?: array<string, mixed>,
 *             }>,
 *             metadata?: array<string, mixed>,
 *         }>,
 *     },
 *     router?: bool|array{ // Router configuration
 *         enabled?: bool|Param, // Default: false
 *         resource?: scalar|Param|null,
 *         type?: scalar|Param|null,
 *         default_uri?: scalar|Param|null, // The default URI used to generate URLs in a non-HTTP context. // Default: null
 *         http_port?: scalar|Param|null, // Default: 80
 *         https_port?: scalar|Param|null, // Default: 443
 *         strict_requirements?: scalar|Param|null, // set to true to throw an exception when a parameter does not match the requirements set to false to disable exceptions when a parameter does not match the requirements (and return null instead) set to null to disable parameter checks against requirements 'true' is the preferred configuration in development mode, while 'false' or 'null' might be preferred in production // Default: true
 *         utf8?: bool|Param, // Default: true
 *     },
 *     session?: bool|array{ // Session configuration
 *         enabled?: bool|Param, // Default: false
 *         storage_factory_id?: scalar|Param|null, // Default: "session.storage.factory.native"
 *         handler_id?: scalar|Param|null, // Defaults to using the native session handler, or to the native *file* session handler if "save_path" is not null.
 *         name?: scalar|Param|null,
 *         cookie_lifetime?: scalar|Param|null,
 *         cookie_path?: scalar|Param|null,
 *         cookie_domain?: scalar|Param|null,
 *         cookie_secure?: true|false|"auto"|Param, // Default: "auto"
 *         cookie_httponly?: bool|Param, // Default: true
 *         cookie_samesite?: null|"lax"|"strict"|"none"|Param, // Default: "lax"
 *         use_cookies?: bool|Param,
 *         gc_divisor?: scalar|Param|null,
 *         gc_probability?: scalar|Param|null,
 *         gc_maxlifetime?: scalar|Param|null,
 *         save_path?: scalar|Param|null, // Defaults to "%kernel.cache_dir%/sessions" if the "handler_id" option is not null.
 *         metadata_update_threshold?: int|Param, // Seconds to wait between 2 session metadata updates. // Default: 0
 *     },
 *     request?: bool|array{ // Request configuration
 *         enabled?: bool|Param, // Default: false
 *         formats?: array<string, Param|string|list<scalar|Param|null>>,
 *     },
 *     assets?: bool|array{ // Assets configuration
 *         enabled?: bool|Param, // Default: true
 *         strict_mode?: bool|Param, // Throw an exception if an entry is missing from the manifest.json. // Default: false
 *         version_strategy?: scalar|Param|null, // Default: null
 *         version?: scalar|Param|null, // Default: null
 *         version_format?: scalar|Param|null, // Default: "%%s?%%s"
 *         json_manifest_path?: scalar|Param|null, // Default: null
 *         base_path?: scalar|Param|null, // Default: ""
 *         base_urls?: Param|string|list<scalar|Param|null>,
 *         packages?: array<string, array{ // Default: []
 *             strict_mode?: bool|Param, // Throw an exception if an entry is missing from the manifest.json. // Default: false
 *             version_strategy?: scalar|Param|null, // Default: null
 *             version?: scalar|Param|null,
 *             version_format?: scalar|Param|null, // Default: null
 *             json_manifest_path?: scalar|Param|null, // Default: null
 *             base_path?: scalar|Param|null, // Default: ""
 *             base_urls?: Param|string|list<scalar|Param|null>,
 *         }>,
 *     },
 *     asset_mapper?: bool|array{ // Asset Mapper configuration
 *         enabled?: bool|Param, // Default: false
 *         paths?: Param|string|array<string, scalar|Param|null>,
 *         excluded_patterns?: list<scalar|Param|null>,
 *         exclude_dotfiles?: bool|Param, // If true, any files starting with "." will be excluded from the asset mapper. // Default: true
 *         server?: bool|Param, // If true, a "dev server" will return the assets from the public directory (true in "debug" mode only by default). // Default: true
 *         public_prefix?: scalar|Param|null, // The public path where the assets will be written to (and served from when "server" is true). // Default: "/assets/"
 *         missing_import_mode?: "strict"|"warn"|"ignore"|Param, // Behavior if an asset cannot be found when imported from JavaScript or CSS files - e.g. "import './non-existent.js'". "strict" means an exception is thrown, "warn" means a warning is logged, "ignore" means the import is left as-is. // Default: "warn"
 *         extensions?: array<string, scalar|Param|null>,
 *         importmap_path?: scalar|Param|null, // The path of the importmap.php file. // Default: "%kernel.project_dir%/importmap.php"
 *         importmap_polyfill?: scalar|Param|null, // The importmap name that will be used to load the polyfill. Set to false to disable. // Default: "es-module-shims"
 *         importmap_script_attributes?: array<string, scalar|Param|null>,
 *         vendor_dir?: scalar|Param|null, // The directory to store JavaScript vendors. // Default: "%kernel.project_dir%/assets/vendor"
 *         precompress?: bool|array{ // Precompress assets with Brotli, Zstandard and gzip.
 *             enabled?: bool|Param, // Default: false
 *             formats?: list<scalar|Param|null>,
 *             extensions?: list<scalar|Param|null>,
 *         },
 *     },
 *     translator?: bool|array{ // Translator configuration
 *         enabled?: bool|Param, // Default: true
 *         fallbacks?: Param|string|list<scalar|Param|null>,
 *         logging?: bool|Param, // Default: false
 *         formatter?: scalar|Param|null, // Default: "translator.formatter.default"
 *         cache_dir?: scalar|Param|null, // Default: "%kernel.cache_dir%/translations"
 *         default_path?: scalar|Param|null, // The default path used to load translations. // Default: "%kernel.project_dir%/translations"
 *         paths?: list<scalar|Param|null>,
 *         pseudo_localization?: bool|array{
 *             enabled?: bool|Param, // Default: false
 *             accents?: bool|Param, // Default: true
 *             expansion_factor?: float|Param, // Default: 1.0
 *             brackets?: bool|Param, // Default: true
 *             parse_html?: bool|Param, // Default: false
 *             localizable_html_attributes?: list<scalar|Param|null>,
 *         },
 *         providers?: array<string, array{ // Default: []
 *             dsn?: scalar|Param|null,
 *             domains?: list<scalar|Param|null>,
 *             locales?: list<scalar|Param|null>,
 *         }>,
 *         globals?: array<string, Param|string|array{ // Default: []
 *             value?: mixed,
 *             message?: string|Param,
 *             parameters?: array<string, scalar|Param|null>,
 *             domain?: string|Param,
 *         }>,
 *     },
 *     validation?: bool|array{ // Validation configuration
 *         enabled?: bool|Param, // Default: true
 *         enable_attributes?: bool|Param, // Default: true
 *         static_method?: Param|string|list<scalar|Param|null>,
 *         translation_domain?: scalar|Param|null, // Default: "validators"
 *         email_validation_mode?: "html5"|"html5-allow-no-tld"|"strict"|Param, // Default: "html5"
 *         mapping?: array{
 *             paths?: list<scalar|Param|null>,
 *         },
 *         not_compromised_password?: bool|array{
 *             enabled?: bool|Param, // When disabled, compromised passwords will be accepted as valid. // Default: true
 *             endpoint?: scalar|Param|null, // API endpoint for the NotCompromisedPassword Validator. // Default: null
 *         },
 *         disable_translation?: bool|Param, // Default: false
 *         property_metadata_existence_check?: bool|Param, // When enabled, validateProperty() and validatePropertyValue() throw an exception if no metadata is found for the given property. // Default: false
 *         auto_mapping?: array<string, array{ // Default: []
 *             services?: list<scalar|Param|null>,
 *         }>,
 *     },
 *     serializer?: bool|array{ // Serializer configuration
 *         enabled?: bool|Param, // Default: false
 *         enable_attributes?: bool|Param, // Default: true
 *         name_converter?: scalar|Param|null,
 *         circular_reference_handler?: scalar|Param|null,
 *         max_depth_handler?: scalar|Param|null,
 *         mapping?: array{
 *             paths?: list<scalar|Param|null>,
 *         },
 *         default_context?: array<string, mixed>,
 *         named_serializers?: array<string, array{ // Default: []
 *             name_converter?: scalar|Param|null,
 *             default_context?: array<string, mixed>,
 *             include_built_in_normalizers?: bool|Param, // Whether to include the built-in normalizers // Default: true
 *             include_built_in_encoders?: bool|Param, // Whether to include the built-in encoders // Default: true
 *         }>,
 *     },
 *     property_access?: bool|array{ // Property access configuration
 *         enabled?: bool|Param, // Default: true
 *         magic_call?: bool|Param, // Default: false
 *         magic_get?: bool|Param, // Default: true
 *         magic_set?: bool|Param, // Default: true
 *         throw_exception_on_invalid_index?: bool|Param, // Default: false
 *         throw_exception_on_invalid_property_path?: bool|Param, // Default: true
 *     },
 *     type_info?: bool|array{ // Type info configuration
 *         enabled?: bool|Param, // Default: true
 *         aliases?: array<string, scalar|Param|null>,
 *     },
 *     property_info?: bool|array{ // Property info configuration
 *         enabled?: bool|Param, // Default: true
 *         with_constructor_extractor?: bool|Param, // Registers the constructor extractor. // Default: true
 *     },
 *     cache?: array{ // Cache configuration
 *         prefix_seed?: scalar|Param|null, // Used to namespace cache keys when using several apps with the same shared backend. // Default: "_%kernel.project_dir%.%kernel.container_class%"
 *         app?: scalar|Param|null, // App related cache pools configuration. // Default: "cache.adapter.filesystem"
 *         system?: scalar|Param|null, // System related cache pools configuration. // Default: "cache.adapter.system"
 *         directory?: scalar|Param|null, // Default: "%kernel.share_dir%/pools/app"
 *         default_psr6_provider?: scalar|Param|null,
 *         default_redis_provider?: scalar|Param|null, // Default: "redis://localhost"
 *         default_valkey_provider?: scalar|Param|null, // Default: "valkey://localhost"
 *         default_memcached_provider?: scalar|Param|null, // Default: "memcached://localhost"
 *         default_doctrine_dbal_provider?: scalar|Param|null, // Default: "database_connection"
 *         default_pdo_provider?: scalar|Param|null, // Default: null
 *         pools?: array<string, array{ // Default: []
 *             adapters?: Param|string|list<scalar|Param|null>,
 *             tags?: scalar|Param|null, // Default: null
 *             public?: bool|Param, // Default: false
 *             default_lifetime?: scalar|Param|null, // Default lifetime of the pool.
 *             provider?: scalar|Param|null, // Overwrite the setting from the default provider for this adapter.
 *             early_expiration_message_bus?: scalar|Param|null,
 *             clearer?: scalar|Param|null,
 *             marshaller?: scalar|Param|null, // The marshaller service to use for this pool.
 *         }>,
 *     },
 *     php_errors?: array{ // PHP errors handling configuration
 *         log?: mixed, // Use the application logger instead of the PHP logger for logging PHP errors. // Default: true
 *         throw?: bool|Param, // Throw PHP errors as \ErrorException instances. // Default: true
 *     },
 *     exceptions?: array<string, array{ // Default: []
 *         log_level?: scalar|Param|null, // The level of log message. Null to let Symfony decide. // Default: null
 *         status_code?: scalar|Param|null, // The status code of the response. Null or 0 to let Symfony decide. // Default: null
 *         log_channel?: scalar|Param|null, // The channel of log message. Null to let Symfony decide. // Default: null
 *     }>,
 *     web_link?: bool|array{ // Web links configuration
 *         enabled?: bool|Param, // Default: false
 *     },
 *     lock?: Param|bool|string|array{ // Lock configuration
 *         enabled?: bool|Param, // Default: false
 *         resources?: Param|string|array<string, Param|string|list<scalar|Param|null>>,
 *     },
 *     semaphore?: Param|bool|string|array{ // Semaphore configuration
 *         enabled?: bool|Param, // Default: false
 *         resources?: Param|string|array<string, scalar|Param|null>,
 *     },
 *     messenger?: bool|array{ // Messenger configuration
 *         enabled?: bool|Param, // Default: false
 *         routing?: array<string, Param|string|list<scalar|Param|null>>,
 *         serializer?: array{
 *             default_serializer?: scalar|Param|null, // Service id to use as the default serializer for the transports. // Default: "messenger.transport.native_php_serializer"
 *             symfony_serializer?: array{
 *                 format?: scalar|Param|null, // Serialization format for the messenger.transport.symfony_serializer service (which is not the serializer used by default). // Default: "json"
 *                 context?: array<string, mixed>,
 *             },
 *         },
 *         transports?: array<string, Param|string|array{ // Default: []
 *             dsn?: scalar|Param|null,
 *             serializer?: scalar|Param|null, // Service id of a custom serializer to use. // Default: null
 *             options?: array<string, mixed>,
 *             failure_transport?: scalar|Param|null, // Transport name to send failed messages to (after all retries have failed). // Default: null
 *             retry_strategy?: Param|string|array{
 *                 service?: scalar|Param|null, // Service id to override the retry strategy entirely. // Default: null
 *                 max_retries?: int|Param, // Default: 3
 *                 delay?: int|Param, // Time in ms to delay (or the initial value when multiplier is used). // Default: 1000
 *                 multiplier?: float|Param, // If greater than 1, delay will grow exponentially for each retry: this delay = (delay * (multiple ^ retries)). // Default: 2
 *                 max_delay?: int|Param, // Max time in ms that a retry should ever be delayed (0 = infinite). // Default: 0
 *                 jitter?: float|Param, // Randomness to apply to the delay (between 0 and 1). // Default: 0.1
 *             },
 *             rate_limiter?: scalar|Param|null, // Rate limiter name to use when processing messages. // Default: null
 *         }>,
 *         failure_transport?: scalar|Param|null, // Transport name to send failed messages to (after all retries have failed). // Default: null
 *         stop_worker_on_signals?: Param|int|string|list<scalar|Param|null>,
 *         default_bus?: scalar|Param|null, // Default: null
 *         buses?: array<string, array{ // Default: {"messenger.bus.default":{"default_middleware":{"enabled":true,"allow_no_handlers":false,"allow_no_senders":true},"middleware":[]}}
 *             default_middleware?: Param|bool|string|array{
 *                 enabled?: bool|Param, // Default: true
 *                 allow_no_handlers?: bool|Param, // Default: false
 *                 allow_no_senders?: bool|Param, // Default: true
 *             },
 *             middleware?: Param|string|list<Param|string|array{ // Default: []
 *                 id?: scalar|Param|null,
 *                 arguments?: list<mixed>,
 *             }>,
 *         }>,
 *     },
 *     scheduler?: bool|array{ // Scheduler configuration
 *         enabled?: bool|Param, // Default: false
 *     },
 *     disallow_search_engine_index?: bool|Param, // Enabled by default when debug is enabled. // Default: true
 *     http_client?: bool|array{ // HTTP Client configuration
 *         enabled?: bool|Param, // Default: true
 *         max_host_connections?: int|Param, // The maximum number of connections to a single host.
 *         default_options?: array{
 *             headers?: array<string, mixed>,
 *             vars?: array<string, mixed>,
 *             max_redirects?: int|Param, // The maximum number of redirects to follow.
 *             http_version?: scalar|Param|null, // The default HTTP version, typically 1.1 or 2.0, leave to null for the best version.
 *             resolve?: array<string, scalar|Param|null>,
 *             proxy?: scalar|Param|null, // The URL of the proxy to pass requests through or null for automatic detection.
 *             no_proxy?: scalar|Param|null, // A comma separated list of hosts that do not require a proxy to be reached.
 *             timeout?: float|Param, // The idle timeout, defaults to the "default_socket_timeout" ini parameter.
 *             max_duration?: float|Param, // The maximum execution time for the request+response as a whole.
 *             bindto?: scalar|Param|null, // A network interface name, IP address, a host name or a UNIX socket to bind to.
 *             verify_peer?: bool|Param, // Indicates if the peer should be verified in a TLS context.
 *             verify_host?: bool|Param, // Indicates if the host should exist as a certificate common name.
 *             cafile?: scalar|Param|null, // A certificate authority file.
 *             capath?: scalar|Param|null, // A directory that contains multiple certificate authority files.
 *             local_cert?: scalar|Param|null, // A PEM formatted certificate file.
 *             local_pk?: scalar|Param|null, // A private key file.
 *             passphrase?: scalar|Param|null, // The passphrase used to encrypt the "local_pk" file.
 *             ciphers?: scalar|Param|null, // A list of TLS ciphers separated by colons, commas or spaces (e.g. "RC3-SHA:TLS13-AES-128-GCM-SHA256"...)
 *             peer_fingerprint?: array{ // Associative array: hashing algorithm => hash(es).
 *                 sha1?: mixed,
 *                 pin-sha256?: mixed,
 *                 md5?: mixed,
 *             },
 *             crypto_method?: scalar|Param|null, // The minimum version of TLS to accept; must be one of STREAM_CRYPTO_METHOD_TLSv*_CLIENT constants.
 *             extra?: array<string, mixed>,
 *             rate_limiter?: scalar|Param|null, // Rate limiter name to use for throttling requests. // Default: null
 *             caching?: bool|array{ // Caching configuration.
 *                 enabled?: bool|Param, // Default: false
 *                 cache_pool?: string|Param, // The taggable cache pool to use for storing the responses. // Default: "cache.http_client"
 *                 shared?: bool|Param, // Indicates whether the cache is shared (public) or private. // Default: true
 *                 max_ttl?: int|Param, // The maximum TTL (in seconds) allowed for cached responses. // Default: 86400
 *             },
 *             retry_failed?: bool|array{
 *                 enabled?: bool|Param, // Default: false
 *                 retry_strategy?: scalar|Param|null, // service id to override the retry strategy. // Default: null
 *                 http_codes?: Param|int|string|array<string, array{ // Default: []
 *                     code?: int|Param,
 *                     methods?: Param|string|list<string|Param>,
 *                 }>,
 *                 max_retries?: int|Param, // Default: 3
 *                 delay?: int|Param, // Time in ms to delay (or the initial value when multiplier is used). // Default: 1000
 *                 multiplier?: float|Param, // If greater than 1, delay will grow exponentially for each retry: delay * (multiple ^ retries). // Default: 2
 *                 max_delay?: int|Param, // Max time in ms that a retry should ever be delayed (0 = infinite). // Default: 0
 *                 jitter?: float|Param, // Randomness in percent (between 0 and 1) to apply to the delay. // Default: 0.1
 *             },
 *         },
 *         mock_response_factory?: scalar|Param|null, // `true` to always return empty 200 responses, or the id of the service to use to generate mock responses - which should be either an invokable or an iterable.
 *         scoped_clients?: array<string, Param|string|array{ // Default: []
 *             scope?: scalar|Param|null, // The regular expression that the request URL must match before adding the other options. When none is provided, the base URI is used instead.
 *             base_uri?: scalar|Param|null, // The URI to resolve relative URLs, following rules in RFC 3985, section 2.
 *             auth_basic?: scalar|Param|null, // An HTTP Basic authentication "username:password".
 *             auth_bearer?: scalar|Param|null, // A token enabling HTTP Bearer authorization.
 *             auth_ntlm?: scalar|Param|null, // A "username:password" pair to use Microsoft NTLM authentication (requires the cURL extension).
 *             query?: array<string, scalar|Param|null>,
 *             headers?: array<string, mixed>,
 *             max_redirects?: int|Param, // The maximum number of redirects to follow.
 *             http_version?: scalar|Param|null, // The default HTTP version, typically 1.1 or 2.0, leave to null for the best version.
 *             resolve?: array<string, scalar|Param|null>,
 *             proxy?: scalar|Param|null, // The URL of the proxy to pass requests through or null for automatic detection.
 *             no_proxy?: scalar|Param|null, // A comma separated list of hosts that do not require a proxy to be reached.
 *             timeout?: float|Param, // The idle timeout, defaults to the "default_socket_timeout" ini parameter.
 *             max_duration?: float|Param, // The maximum execution time for the request+response as a whole.
 *             bindto?: scalar|Param|null, // A network interface name, IP address, a host name or a UNIX socket to bind to.
 *             verify_peer?: bool|Param, // Indicates if the peer should be verified in a TLS context.
 *             verify_host?: bool|Param, // Indicates if the host should exist as a certificate common name.
 *             cafile?: scalar|Param|null, // A certificate authority file.
 *             capath?: scalar|Param|null, // A directory that contains multiple certificate authority files.
 *             local_cert?: scalar|Param|null, // A PEM formatted certificate file.
 *             local_pk?: scalar|Param|null, // A private key file.
 *             passphrase?: scalar|Param|null, // The passphrase used to encrypt the "local_pk" file.
 *             ciphers?: scalar|Param|null, // A list of TLS ciphers separated by colons, commas or spaces (e.g. "RC3-SHA:TLS13-AES-128-GCM-SHA256"...).
 *             peer_fingerprint?: array{ // Associative array: hashing algorithm => hash(es).
 *                 sha1?: mixed,
 *                 pin-sha256?: mixed,
 *                 md5?: mixed,
 *             },
 *             crypto_method?: scalar|Param|null, // The minimum version of TLS to accept; must be one of STREAM_CRYPTO_METHOD_TLSv*_CLIENT constants.
 *             mock_response_factory?: scalar|Param|null, // `true` to always return empty 200 responses, `false` to disable mocking, or the id of the service to use to generate mock responses (invokable or iterable).
 *             extra?: array<string, mixed>,
 *             rate_limiter?: scalar|Param|null, // Rate limiter name to use for throttling requests. // Default: null
 *             caching?: bool|array{ // Caching configuration.
 *                 enabled?: bool|Param, // Default: false
 *                 cache_pool?: string|Param, // The taggable cache pool to use for storing the responses. // Default: "cache.http_client"
 *                 shared?: bool|Param, // Indicates whether the cache is shared (public) or private. // Default: true
 *                 max_ttl?: int|Param, // The maximum TTL (in seconds) allowed for cached responses. // Default: 86400
 *             },
 *             retry_failed?: bool|array{
 *                 enabled?: bool|Param, // Default: false
 *                 retry_strategy?: scalar|Param|null, // service id to override the retry strategy. // Default: null
 *                 http_codes?: Param|int|string|array<string, array{ // Default: []
 *                     code?: int|Param,
 *                     methods?: Param|string|list<string|Param>,
 *                 }>,
 *                 max_retries?: int|Param, // Default: 3
 *                 delay?: int|Param, // Time in ms to delay (or the initial value when multiplier is used). // Default: 1000
 *                 multiplier?: float|Param, // If greater than 1, delay will grow exponentially for each retry: delay * (multiple ^ retries). // Default: 2
 *                 max_delay?: int|Param, // Max time in ms that a retry should ever be delayed (0 = infinite). // Default: 0
 *                 jitter?: float|Param, // Randomness in percent (between 0 and 1) to apply to the delay. // Default: 0.1
 *             },
 *         }>,
 *     },
 *     mailer?: bool|array{ // Mailer configuration
 *         enabled?: bool|Param, // Default: false
 *         message_bus?: scalar|Param|null, // The message bus to use. Defaults to the default bus if the Messenger component is installed. // Default: null
 *         dsn?: scalar|Param|null, // Default: null
 *         transports?: array<string, scalar|Param|null>,
 *         envelope?: array{ // Mailer Envelope configuration
 *             sender?: scalar|Param|null,
 *             recipients?: Param|string|list<scalar|Param|null>,
 *             allowed_recipients?: Param|string|list<scalar|Param|null>,
 *         },
 *         headers?: array<string, Param|string|array{ // Default: []
 *             value?: mixed,
 *         }>,
 *         dkim_signer?: bool|array{ // DKIM signer configuration
 *             enabled?: bool|Param, // Default: false
 *             key?: scalar|Param|null, // Key content, or path to key (in PEM format with the `file://` prefix) // Default: ""
 *             domain?: scalar|Param|null, // Default: ""
 *             select?: scalar|Param|null, // Default: ""
 *             passphrase?: scalar|Param|null, // The private key passphrase // Default: ""
 *             options?: array<string, mixed>,
 *         },
 *         smime_signer?: bool|array{ // S/MIME signer configuration
 *             enabled?: bool|Param, // Default: false
 *             key?: scalar|Param|null, // Path to key (in PEM format) // Default: ""
 *             certificate?: scalar|Param|null, // Path to certificate (in PEM format without the `file://` prefix) // Default: ""
 *             passphrase?: scalar|Param|null, // The private key passphrase // Default: null
 *             extra_certificates?: scalar|Param|null, // Default: null
 *             sign_options?: int|Param, // Default: null
 *         },
 *         smime_encrypter?: bool|array{ // S/MIME encrypter configuration
 *             enabled?: bool|Param, // Default: false
 *             repository?: scalar|Param|null, // S/MIME certificate repository service. This service shall implement the `Symfony\Component\Mailer\EventListener\SmimeCertificateRepositoryInterface`. // Default: ""
 *             cipher?: int|Param, // A set of algorithms used to encrypt the message // Default: null
 *         },
 *     },
 *     secrets?: bool|array{
 *         enabled?: bool|Param, // Default: true
 *         vault_directory?: scalar|Param|null, // Default: "%kernel.project_dir%/config/secrets/%kernel.runtime_environment%"
 *         local_dotenv_file?: scalar|Param|null, // Default: "%kernel.project_dir%/.env.%kernel.environment%.local"
 *         decryption_env_var?: scalar|Param|null, // Default: "base64:default::SYMFONY_DECRYPTION_SECRET"
 *     },
 *     notifier?: bool|array{ // Notifier configuration
 *         enabled?: bool|Param, // Default: false
 *         message_bus?: scalar|Param|null, // The message bus to use. Defaults to the default bus if the Messenger component is installed. // Default: null
 *         chatter_transports?: array<string, scalar|Param|null>,
 *         texter_transports?: array<string, scalar|Param|null>,
 *         notification_on_failed_messages?: bool|Param, // Default: false
 *         channel_policy?: array<string, Param|string|list<scalar|Param|null>>,
 *         admin_recipients?: list<array{ // Default: []
 *             email?: scalar|Param|null,
 *             phone?: scalar|Param|null, // Default: ""
 *         }>,
 *     },
 *     rate_limiter?: bool|array{ // Rate limiter configuration
 *         enabled?: bool|Param, // Default: false
 *         limiters?: array<string, array{ // Default: []
 *             lock_factory?: scalar|Param|null, // The service ID of the lock factory used by this limiter (or null to disable locking). // Default: "auto"
 *             cache_pool?: scalar|Param|null, // The cache pool to use for storing the current limiter state. // Default: "cache.rate_limiter"
 *             storage_service?: scalar|Param|null, // The service ID of a custom storage implementation, this precedes any configured "cache_pool". // Default: null
 *             policy?: "fixed_window"|"token_bucket"|"sliding_window"|"compound"|"no_limit"|Param, // The algorithm to be used by this limiter.
 *             limiters?: Param|string|list<scalar|Param|null>,
 *             limit?: int|Param, // The maximum allowed hits in a fixed interval or burst.
 *             interval?: scalar|Param|null, // Configures the fixed interval if "policy" is set to "fixed_window" or "sliding_window". The value must be a number followed by "second", "minute", "hour", "day", "week" or "month" (or their plural equivalent).
 *             rate?: array{ // Configures the fill rate if "policy" is set to "token_bucket".
 *                 interval?: scalar|Param|null, // Configures the rate interval. The value must be a number followed by "second", "minute", "hour", "day", "week" or "month" (or their plural equivalent).
 *                 amount?: int|Param, // Amount of tokens to add each interval. // Default: 1
 *             },
 *             anchor_at?: scalar|Param|null, // Aligns the "fixed_window" policy to a calendar (e.g. "2024-01-05 00:00:00 UTC" combined with `interval: 1 month` resets the counter on the 5th of each month). UTC if not specified. // Default: null
 *         }>,
 *     },
 *     uid?: bool|array{ // Uid configuration
 *         enabled?: bool|Param, // Default: false
 *         default_uuid_version?: 7|6|4|1|Param, // Default: 7
 *         name_based_uuid_version?: 5|3|Param, // Default: 5
 *         name_based_uuid_namespace?: scalar|Param|null,
 *         time_based_uuid_version?: 7|6|1|Param, // Default: 7
 *         time_based_uuid_node?: scalar|Param|null,
 *         uuid47_secret?: scalar|Param|null, // A high-entropy secret used by the "uuid47_transformer" service. Defaults to "kernel.secret". // Default: null
 *     },
 *     html_sanitizer?: bool|array{ // HtmlSanitizer configuration
 *         enabled?: bool|Param, // Default: true
 *         sanitizers?: array<string, array{ // Default: []
 *             default_action?: "drop"|"block"|"allow"|Param, // Defines how the sanitizer must behave by default.
 *             allow_safe_elements?: bool|Param, // Allows "safe" elements and attributes. // Default: false
 *             allow_static_elements?: bool|Param, // Allows all static elements and attributes from the W3C Sanitizer API standard. // Default: false
 *             allow_elements?: array<string, mixed>,
 *             block_elements?: Param|string|list<string|Param>,
 *             drop_elements?: Param|string|list<string|Param>,
 *             allow_attributes?: array<string, mixed>,
 *             drop_attributes?: array<string, mixed>,
 *             force_attributes?: array<string, array<string, string|Param>>,
 *             force_https_urls?: bool|Param, // Transforms URLs using the HTTP scheme to use the HTTPS scheme instead. // Default: false
 *             allowed_link_schemes?: Param|string|list<string|Param>,
 *             allowed_link_hosts?: Param|null|string|list<string|Param>,
 *             allow_relative_links?: bool|Param, // Allows relative URLs to be used in links href attributes. // Default: false
 *             allowed_media_schemes?: Param|string|list<string|Param>,
 *             allowed_media_hosts?: Param|null|string|list<string|Param>,
 *             allow_relative_medias?: bool|Param, // Allows relative URLs to be used in media source attributes (img, audio, video, ...). // Default: false
 *             with_attribute_sanitizers?: Param|string|list<string|Param>,
 *             without_attribute_sanitizers?: Param|string|list<string|Param>,
 *             max_input_length?: int|Param, // The maximum length allowed for the sanitized input. // Default: 0
 *         }>,
 *     },
 *     webhook?: bool|array{ // Webhook configuration
 *         enabled?: bool|Param, // Default: false
 *         message_bus?: scalar|Param|null, // The message bus to use. // Default: "messenger.default_bus"
 *         event_header_name?: scalar|Param|null, // Default: "Webhook-Event"
 *         id_header_name?: scalar|Param|null, // Default: "Webhook-Id"
 *         signature_header_name?: scalar|Param|null, // Default: "Webhook-Signature"
 *         signing_algorithm?: scalar|Param|null, // Default: "sha256"
 *         routing?: array<string, array{ // Default: []
 *             service?: scalar|Param|null,
 *             secret?: scalar|Param|null, // The secret used to verify incoming request signatures. It must be set in production: with an empty value, depending on the parser, requests from any sender are accepted or every request is rejected. // Default: ""
 *         }>,
 *     },
 *     remote_event?: bool|array{ // RemoteEvent configuration
 *         enabled?: bool|Param, // Default: false
 *     },
 *     json_streamer?: bool|array{ // JSON streamer configuration
 *         enabled?: bool|Param, // Default: false
 *         default_options?: array{
 *             include_null_properties?: bool|Param, // Encode the properties with null value // Default: false
 *             ...<string, mixed>
 *         },
 *     },
 * }
 * @psalm-type DoctrineConfig = array{
 *     dbal?: array{
 *         default_connection?: scalar|Param|null,
 *         types?: array<string, Param|string|array{ // Default: []
 *             class?: scalar|Param|null,
 *         }>,
 *         driver_schemes?: array<string, scalar|Param|null>,
 *         connections?: array<string, array{ // Default: []
 *             url?: scalar|Param|null, // A URL with connection information; any parameter value parsed from this string will override explicitly set parameters
 *             dbname?: scalar|Param|null,
 *             host?: scalar|Param|null, // Defaults to "localhost" at runtime.
 *             port?: scalar|Param|null, // Defaults to null at runtime.
 *             user?: scalar|Param|null, // Defaults to "root" at runtime.
 *             password?: scalar|Param|null, // Defaults to null at runtime.
 *             dbname_suffix?: scalar|Param|null, // Adds the given suffix to the configured database name, this option has no effects for the SQLite platform
 *             application_name?: scalar|Param|null,
 *             charset?: scalar|Param|null,
 *             path?: scalar|Param|null,
 *             memory?: bool|Param,
 *             unix_socket?: scalar|Param|null, // The unix socket to use for MySQL
 *             persistent?: bool|Param, // True to use as persistent connection for the ibm_db2 driver
 *             protocol?: scalar|Param|null, // The protocol to use for the ibm_db2 driver (default to TCPIP if omitted)
 *             service?: bool|Param, // True to use SERVICE_NAME as connection parameter instead of SID for Oracle
 *             servicename?: scalar|Param|null, // Overrules dbname parameter if given and used as SERVICE_NAME or SID connection parameter for Oracle depending on the service parameter.
 *             sessionMode?: scalar|Param|null, // The session mode to use for the oci8 driver
 *             server?: scalar|Param|null, // The name of a running database server to connect to for SQL Anywhere.
 *             default_dbname?: scalar|Param|null, // Override the default database (postgres) to connect to for PostgreSQL connection.
 *             sslmode?: scalar|Param|null, // Determines whether or with what priority a SSL TCP/IP connection will be negotiated with the server for PostgreSQL.
 *             sslrootcert?: scalar|Param|null, // The name of a file containing SSL certificate authority (CA) certificate(s). If the file exists, the server's certificate will be verified to be signed by one of these authorities.
 *             sslcert?: scalar|Param|null, // The path to the SSL client certificate file for PostgreSQL.
 *             sslkey?: scalar|Param|null, // The path to the SSL client key file for PostgreSQL.
 *             sslcrl?: scalar|Param|null, // The file name of the SSL certificate revocation list for PostgreSQL.
 *             pooled?: bool|Param, // True to use a pooled server with the oci8/pdo_oracle driver
 *             MultipleActiveResultSets?: bool|Param, // Configuring MultipleActiveResultSets for the pdo_sqlsrv driver
 *             instancename?: scalar|Param|null, // Optional parameter, complete whether to add the INSTANCE_NAME parameter in the connection. It is generally used to connect to an Oracle RAC server to select the name of a particular instance.
 *             connectstring?: scalar|Param|null, // Complete Easy Connect connection descriptor, see https://docs.oracle.com/database/121/NETAG/naming.htm.When using this option, you will still need to provide the user and password parameters, but the other parameters will no longer be used. Note that when using this parameter, the getHost and getPort methods from Doctrine\DBAL\Connection will no longer function as expected.
 *             driver?: scalar|Param|null, // Default: "pdo_mysql"
 *             auto_commit?: bool|Param,
 *             schema_filter?: scalar|Param|null,
 *             logging?: bool|Param, // Default: true
 *             profiling?: bool|Param, // Default: true
 *             profiling_collect_backtrace?: bool|Param, // Enables collecting backtraces when profiling is enabled // Default: false
 *             profiling_collect_schema_errors?: bool|Param, // Enables collecting schema errors when profiling is enabled // Default: true
 *             server_version?: scalar|Param|null,
 *             idle_connection_ttl?: int|Param, // Default: 600
 *             driver_class?: scalar|Param|null,
 *             wrapper_class?: scalar|Param|null,
 *             keep_replica?: bool|Param,
 *             options?: array<string, mixed>,
 *             mapping_types?: array<string, scalar|Param|null>,
 *             default_table_options?: array<string, scalar|Param|null>,
 *             schema_manager_factory?: scalar|Param|null, // Default: "doctrine.dbal.default_schema_manager_factory"
 *             result_cache?: scalar|Param|null,
 *             replicas?: array<string, array{ // Default: []
 *                 url?: scalar|Param|null, // A URL with connection information; any parameter value parsed from this string will override explicitly set parameters
 *                 dbname?: scalar|Param|null,
 *                 host?: scalar|Param|null, // Defaults to "localhost" at runtime.
 *                 port?: scalar|Param|null, // Defaults to null at runtime.
 *                 user?: scalar|Param|null, // Defaults to "root" at runtime.
 *                 password?: scalar|Param|null, // Defaults to null at runtime.
 *                 dbname_suffix?: scalar|Param|null, // Adds the given suffix to the configured database name, this option has no effects for the SQLite platform
 *                 application_name?: scalar|Param|null,
 *                 charset?: scalar|Param|null,
 *                 path?: scalar|Param|null,
 *                 memory?: bool|Param,
 *                 unix_socket?: scalar|Param|null, // The unix socket to use for MySQL
 *                 persistent?: bool|Param, // True to use as persistent connection for the ibm_db2 driver
 *                 protocol?: scalar|Param|null, // The protocol to use for the ibm_db2 driver (default to TCPIP if omitted)
 *                 service?: bool|Param, // True to use SERVICE_NAME as connection parameter instead of SID for Oracle
 *                 servicename?: scalar|Param|null, // Overrules dbname parameter if given and used as SERVICE_NAME or SID connection parameter for Oracle depending on the service parameter.
 *                 sessionMode?: scalar|Param|null, // The session mode to use for the oci8 driver
 *                 server?: scalar|Param|null, // The name of a running database server to connect to for SQL Anywhere.
 *                 default_dbname?: scalar|Param|null, // Override the default database (postgres) to connect to for PostgreSQL connection.
 *                 sslmode?: scalar|Param|null, // Determines whether or with what priority a SSL TCP/IP connection will be negotiated with the server for PostgreSQL.
 *                 sslrootcert?: scalar|Param|null, // The name of a file containing SSL certificate authority (CA) certificate(s). If the file exists, the server's certificate will be verified to be signed by one of these authorities.
 *                 sslcert?: scalar|Param|null, // The path to the SSL client certificate file for PostgreSQL.
 *                 sslkey?: scalar|Param|null, // The path to the SSL client key file for PostgreSQL.
 *                 sslcrl?: scalar|Param|null, // The file name of the SSL certificate revocation list for PostgreSQL.
 *                 pooled?: bool|Param, // True to use a pooled server with the oci8/pdo_oracle driver
 *                 MultipleActiveResultSets?: bool|Param, // Configuring MultipleActiveResultSets for the pdo_sqlsrv driver
 *                 instancename?: scalar|Param|null, // Optional parameter, complete whether to add the INSTANCE_NAME parameter in the connection. It is generally used to connect to an Oracle RAC server to select the name of a particular instance.
 *                 connectstring?: scalar|Param|null, // Complete Easy Connect connection descriptor, see https://docs.oracle.com/database/121/NETAG/naming.htm.When using this option, you will still need to provide the user and password parameters, but the other parameters will no longer be used. Note that when using this parameter, the getHost and getPort methods from Doctrine\DBAL\Connection will no longer function as expected.
 *                 ...<string, mixed>
 *             }>,
 *             ...<string, mixed>
 *         }>,
 *         ...<string, mixed>
 *     },
 *     orm?: array{
 *         default_entity_manager?: scalar|Param|null,
 *         enable_native_lazy_objects?: bool|Param, // Deprecated: The "enable_native_lazy_objects" option is deprecated and will be removed in DoctrineBundle 4.0, as native lazy objects are now always enabled. // Default: true
 *         controller_resolver?: bool|array{
 *             enabled?: bool|Param, // Default: true
 *             auto_mapping?: bool|Param, // Deprecated: The "doctrine.orm.controller_resolver.auto_mapping.auto_mapping" option is deprecated and will be removed in DoctrineBundle 4.0, as it only accepts `false` since 3.0. // Set to true to enable using route placeholders as lookup criteria when the primary key doesn't match the argument name // Default: false
 *             evict_cache?: bool|Param, // Set to true to fetch the entity from the database instead of using the cache, if any // Default: false
 *         },
 *         entity_managers?: array<string, array{ // Default: []
 *             query_cache_driver?: Param|string|array{
 *                 type?: scalar|Param|null, // Default: null
 *                 id?: scalar|Param|null,
 *                 pool?: scalar|Param|null,
 *             },
 *             metadata_cache_driver?: Param|string|array{
 *                 type?: scalar|Param|null, // Default: null
 *                 id?: scalar|Param|null,
 *                 pool?: scalar|Param|null,
 *             },
 *             result_cache_driver?: Param|string|array{
 *                 type?: scalar|Param|null, // Default: null
 *                 id?: scalar|Param|null,
 *                 pool?: scalar|Param|null,
 *             },
 *             entity_listeners?: array{
 *                 entities?: array<string, array{ // Default: []
 *                     listeners?: array<string, array{ // Default: []
 *                         events?: list<array{ // Default: []
 *                             type?: scalar|Param|null,
 *                             method?: scalar|Param|null, // Default: null
 *                         }>,
 *                     }>,
 *                 }>,
 *                 ...<string, mixed>
 *             },
 *             connection?: scalar|Param|null,
 *             class_metadata_factory_name?: scalar|Param|null, // Default: "Doctrine\\ORM\\Mapping\\ClassMetadataFactory"
 *             default_repository_class?: scalar|Param|null, // Default: "Doctrine\\ORM\\EntityRepository"
 *             auto_mapping?: scalar|Param|null, // Default: false
 *             auto_discover_entities?: bool|Param|null, // Registers all entity classes discovered by the container scan, removing the need for explicit "mappings". When not set, enabled if neither "mappings" nor "auto_mapping" is configured and Symfony 8.2 and doctrine/orm 3.6 are available. // Default: null
 *             naming_strategy?: scalar|Param|null, // Default: "doctrine.orm.naming_strategy.default"
 *             quote_strategy?: scalar|Param|null, // Default: "doctrine.orm.quote_strategy.default"
 *             typed_field_mapper?: scalar|Param|null, // Default: "doctrine.orm.typed_field_mapper.default"
 *             entity_listener_resolver?: scalar|Param|null, // Default: null
 *             fetch_mode_subselect_batch_size?: scalar|Param|null,
 *             use_schema_editor_api?: bool|Param|null, // Set to "true" or "false" to explicitly opt in or out of the schema editor API for schema generation. Requires doctrine/dbal 4.5 or higher and doctrine/orm 3.8 or higher. // Default: null
 *             repository_factory?: scalar|Param|null, // Default: "doctrine.orm.container_repository_factory"
 *             schema_ignore_classes?: list<scalar|Param|null>,
 *             validate_xml_mapping?: bool|Param, // Set to "true" to opt-in to the new mapping driver mode that was added in Doctrine ORM 2.14 and will be mandatory in ORM 3.0. See https://github.com/doctrine/orm/pull/6728. // Default: false
 *             second_level_cache?: array{
 *                 region_cache_driver?: Param|string|array{
 *                     type?: scalar|Param|null, // Default: null
 *                     id?: scalar|Param|null,
 *                     pool?: scalar|Param|null,
 *                 },
 *                 region_lock_lifetime?: scalar|Param|null, // Default: 60
 *                 log_enabled?: bool|Param, // Default: true
 *                 region_lifetime?: scalar|Param|null, // Default: 3600
 *                 enabled?: bool|Param, // Default: true
 *                 factory?: scalar|Param|null,
 *                 regions?: array<string, array{ // Default: []
 *                     cache_driver?: Param|string|array{
 *                         type?: scalar|Param|null, // Default: null
 *                         id?: scalar|Param|null,
 *                         pool?: scalar|Param|null,
 *                     },
 *                     lock_path?: scalar|Param|null, // Default: "%kernel.cache_dir%/doctrine/orm/slc/filelock"
 *                     lock_lifetime?: scalar|Param|null, // Default: 60
 *                     type?: scalar|Param|null, // Default: "default"
 *                     lifetime?: scalar|Param|null, // Default: null
 *                     service?: scalar|Param|null,
 *                     name?: scalar|Param|null,
 *                 }>,
 *                 loggers?: array<string, array{ // Default: []
 *                     name?: scalar|Param|null,
 *                     service?: scalar|Param|null,
 *                 }>,
 *             },
 *             hydrators?: array<string, scalar|Param|null>,
 *             mappings?: array<string, Param|bool|string|array{ // Default: []
 *                 mapping?: scalar|Param|null, // Default: true
 *                 type?: scalar|Param|null,
 *                 dir?: scalar|Param|null,
 *                 alias?: scalar|Param|null, // Deprecated: The "doctrine.orm.entity_managers..mappings..alias.alias" option is deprecated and will be removed in DoctrineBundle 4.0, as Doctrine ORM no longer supports entity namespace aliases.
 *                 prefix?: scalar|Param|null,
 *                 is_bundle?: bool|Param,
 *             }>,
 *             dql?: array{
 *                 string_functions?: array<string, scalar|Param|null>,
 *                 numeric_functions?: array<string, scalar|Param|null>,
 *                 datetime_functions?: array<string, scalar|Param|null>,
 *             },
 *             filters?: array<string, Param|string|array{ // Default: []
 *                 class?: scalar|Param|null,
 *                 enabled?: bool|Param, // Default: false
 *                 parameters?: array<string, mixed>,
 *                 ...<string, mixed>
 *             }>,
 *             identity_generation_preferences?: array<string, scalar|Param|null>,
 *         }>,
 *         resolve_target_entities?: array<string, scalar|Param|null>,
 *         ...<string, mixed>
 *     },
 * }
 * @psalm-type DoctrineMigrationsConfig = array{
 *     enable_service_migrations?: bool|Param, // Whether to enable fetching migrations from the service container. // Default: false
 *     migrations_paths?: array<string, scalar|Param|null>,
 *     services?: array<string, scalar|Param|null>,
 *     factories?: array<string, scalar|Param|null>,
 *     storage?: array{ // Storage to use for migration status metadata.
 *         table_storage?: array{ // The default metadata storage, implemented as a table in the database.
 *             table_name?: scalar|Param|null, // Default: null
 *             version_column_name?: scalar|Param|null, // Default: null
 *             version_column_length?: scalar|Param|null, // Default: null
 *             executed_at_column_name?: scalar|Param|null, // Default: null
 *             execution_time_column_name?: scalar|Param|null, // Default: null
 *         },
 *     },
 *     migrations?: list<scalar|Param|null>,
 *     connection?: scalar|Param|null, // Connection name to use for the migrations database. // Default: null
 *     em?: scalar|Param|null, // Entity manager name to use for the migrations database (available when doctrine/orm is installed). // Default: null
 *     all_or_nothing?: scalar|Param|null, // Run all migrations in a transaction. // Default: false
 *     check_database_platform?: scalar|Param|null, // Adds an extra check in the generated migrations to allow execution only on the same platform as they were initially generated on. // Default: true
 *     custom_template?: scalar|Param|null, // Custom template path for generated migration classes. // Default: null
 *     organize_migrations?: scalar|Param|null, // Organize migrations mode. Possible values are: "BY_YEAR", "BY_YEAR_AND_MONTH", false // Default: false
 *     enable_profiler?: bool|Param, // Whether or not to enable the profiler collector to calculate and visualize migration status. This adds some queries overhead. // Default: false
 *     transactional?: bool|Param, // Whether or not to wrap migrations in a single transaction. // Default: true
 * }
 * @psalm-type SecurityConfig = array{
 *     access_denied_url?: scalar|Param|null, // Default: null
 *     session_fixation_strategy?: "none"|"migrate"|"invalidate"|Param, // Default: "migrate"
 *     expose_security_errors?: \Symfony\Component\Security\Http\Authentication\ExposeSecurityLevel::None|\Symfony\Component\Security\Http\Authentication\ExposeSecurityLevel::AccountStatus|\Symfony\Component\Security\Http\Authentication\ExposeSecurityLevel::All|Param, // Default: "none"
 *     erase_credentials?: bool|Param, // Deprecated: Setting the "security.erase_credentials.erase_credentials" configuration option is deprecated. It will be removed in Symfony 9.0, as the "eraseCredentials()" method was removed in Symfony 8.0. // Default: true
 *     access_decision_manager?: array{
 *         strategy?: "affirmative"|"consensus"|"unanimous"|"priority"|Param,
 *         service?: scalar|Param|null,
 *         strategy_service?: scalar|Param|null,
 *         allow_if_all_abstain?: bool|Param, // Default: false
 *         allow_if_equal_granted_denied?: bool|Param, // Default: true
 *     },
 *     password_hashers?: array<string, Param|string|array{ // Default: []
 *         algorithm?: scalar|Param|null,
 *         migrate_from?: Param|string|list<scalar|Param|null>,
 *         hash_algorithm?: scalar|Param|null, // Name of hashing algorithm for PBKDF2 (i.e. sha256, sha512, etc..) See hash_algos() for a list of supported algorithms. // Default: "sha512"
 *         key_length?: scalar|Param|null, // Default: 40
 *         ignore_case?: bool|Param, // Default: false
 *         encode_as_base64?: bool|Param, // Default: true
 *         iterations?: scalar|Param|null, // Default: 5000
 *         cost?: int|Param, // Default: null
 *         memory_cost?: scalar|Param|null, // Default: null
 *         time_cost?: scalar|Param|null, // Default: null
 *         id?: scalar|Param|null,
 *     }>,
 *     providers?: array<string, array{ // Default: []
 *         id?: scalar|Param|null,
 *         chain?: array{
 *             providers?: Param|string|list<scalar|Param|null>,
 *         },
 *         entity?: array{
 *             class?: scalar|Param|null, // The full entity class name of your user class.
 *             property?: scalar|Param|null, // Default: null
 *             manager_name?: scalar|Param|null, // Default: null
 *         },
 *         memory?: array{
 *             users?: array<string, array{ // Default: []
 *                 password?: scalar|Param|null, // Default: null
 *                 roles?: Param|string|list<scalar|Param|null>,
 *             }>,
 *         },
 *         ldap?: array{
 *             service?: scalar|Param|null,
 *             base_dn?: scalar|Param|null,
 *             search_dn?: scalar|Param|null, // Default: null
 *             search_password?: scalar|Param|null, // Default: null
 *             extra_fields?: list<scalar|Param|null>,
 *             default_roles?: Param|string|list<scalar|Param|null>,
 *             role_fetcher?: scalar|Param|null, // Default: null
 *             uid_key?: scalar|Param|null, // Default: "sAMAccountName"
 *             filter?: scalar|Param|null, // Default: "({uid_key}={user_identifier})"
 *             password_attribute?: scalar|Param|null, // Default: null
 *         },
 *     }>,
 *     firewalls?: array<string, array{ // Default: []
 *         pattern?: scalar|Param|null,
 *         host?: scalar|Param|null,
 *         methods?: Param|string|list<scalar|Param|null>,
 *         security?: bool|Param, // Default: true
 *         user_checker?: scalar|Param|null, // The UserChecker to use when authenticating users in this firewall. // Default: "security.user_checker"
 *         request_matcher?: scalar|Param|null,
 *         access_denied_url?: scalar|Param|null,
 *         access_denied_handler?: scalar|Param|null,
 *         entry_point?: scalar|Param|null, // An enabled authenticator name or a service id that implements "Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface".
 *         provider?: scalar|Param|null,
 *         stateless?: bool|Param, // Default: false
 *         lazy?: bool|Param, // Default: false
 *         context?: scalar|Param|null,
 *         logout?: array{
 *             enable_csrf?: bool|Param|null, // Default: null
 *             csrf_token_id?: scalar|Param|null, // Default: "logout"
 *             csrf_parameter?: scalar|Param|null, // Default: "_csrf_token"
 *             csrf_token_manager?: scalar|Param|null,
 *             path?: scalar|Param|null, // Default: "/logout"
 *             target?: scalar|Param|null, // Default: "/"
 *             invalidate_session?: bool|Param, // Default: true
 *             clear_site_data?: Param|string|list<"*"|"cache"|"cookies"|"storage"|"clientHints"|"executionContexts"|"prefetchCache"|"prerenderCache"|Param>,
 *             delete_cookies?: Param|string|array<string, array{ // Default: []
 *                 path?: scalar|Param|null, // Default: null
 *                 domain?: scalar|Param|null, // Default: null
 *                 secure?: scalar|Param|null, // Default: false
 *                 samesite?: scalar|Param|null, // Default: null
 *                 partitioned?: scalar|Param|null, // Default: false
 *             }>,
 *         },
 *         switch_user?: array{
 *             provider?: scalar|Param|null,
 *             parameter?: scalar|Param|null, // Default: "_switch_user"
 *             role?: scalar|Param|null, // Default: "ROLE_ALLOWED_TO_SWITCH"
 *             target_route?: scalar|Param|null, // Default: null
 *         },
 *         required_badges?: list<scalar|Param|null>,
 *         custom_authenticators?: list<scalar|Param|null>,
 *         login_throttling?: array{
 *             limiter?: scalar|Param|null, // A service id implementing "Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface".
 *             max_attempts?: int|Param, // Default: 5
 *             interval?: scalar|Param|null, // Default: "1 minute"
 *             lock_factory?: scalar|Param|null, // The service ID of the lock factory used by the login rate limiter ("auto" to use the default one when the Lock component is configured, or null to disable locking). // Default: "auto"
 *             cache_pool?: string|Param, // The cache pool to use for storing the limiter state // Default: "cache.rate_limiter"
 *             storage_service?: string|Param, // The service ID of a custom storage implementation, this precedes any configured "cache_pool" // Default: null
 *         },
 *         x509?: array{
 *             provider?: scalar|Param|null,
 *             user?: scalar|Param|null, // Default: "SSL_CLIENT_S_DN_Email"
 *             credentials?: scalar|Param|null, // Default: "SSL_CLIENT_S_DN"
 *             user_identifier?: scalar|Param|null, // Default: "emailAddress"
 *         },
 *         remote_user?: array{
 *             provider?: scalar|Param|null,
 *             user?: scalar|Param|null, // Default: "REMOTE_USER"
 *         },
 *         login_link?: array{
 *             check_route?: scalar|Param|null, // Route that will validate the login link - e.g. "app_login_link_verify".
 *             check_post_only?: scalar|Param|null, // If true, only HTTP POST requests to "check_route" will be handled by the authenticator. // Default: false
 *             signature_properties?: list<scalar|Param|null>,
 *             lifetime?: int|Param, // The lifetime of the login link in seconds. // Default: 600
 *             max_uses?: int|Param, // Max number of times a login link can be used - null means unlimited within lifetime. // Default: null
 *             used_link_cache?: scalar|Param|null, // Cache service id used to expired links of max_uses is set.
 *             success_handler?: scalar|Param|null, // A service id that implements Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface.
 *             failure_handler?: scalar|Param|null, // A service id that implements Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface.
 *             provider?: scalar|Param|null, // The user provider to load users from.
 *             secret?: scalar|Param|null, // Default: "%kernel.secret%"
 *             always_use_default_target_path?: bool|Param, // Default: false
 *             default_target_path?: scalar|Param|null, // Default: "/"
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             target_path_parameter?: scalar|Param|null, // Default: "_target_path"
 *             use_referer?: bool|Param, // Default: false
 *             failure_path?: scalar|Param|null, // Default: null
 *             failure_forward?: bool|Param, // Default: false
 *             failure_path_parameter?: scalar|Param|null, // Default: "_failure_path"
 *         },
 *         form_login?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             check_path?: scalar|Param|null, // Default: "/login_check"
 *             use_forward?: bool|Param, // Default: false
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             username_parameter?: scalar|Param|null, // Default: "_username"
 *             password_parameter?: scalar|Param|null, // Default: "_password"
 *             csrf_parameter?: scalar|Param|null, // Default: "_csrf_token"
 *             csrf_token_id?: scalar|Param|null, // Default: "authenticate"
 *             enable_csrf?: bool|Param, // Default: false
 *             post_only?: bool|Param, // Default: true
 *             form_only?: bool|Param, // Default: false
 *             always_use_default_target_path?: bool|Param, // Default: false
 *             default_target_path?: scalar|Param|null, // Default: "/"
 *             target_path_parameter?: scalar|Param|null, // Default: "_target_path"
 *             use_referer?: bool|Param, // Default: false
 *             failure_path?: scalar|Param|null, // Default: null
 *             failure_forward?: bool|Param, // Default: false
 *             failure_path_parameter?: scalar|Param|null, // Default: "_failure_path"
 *         },
 *         form_login_ldap?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             check_path?: scalar|Param|null, // Default: "/login_check"
 *             use_forward?: bool|Param, // Default: false
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             username_parameter?: scalar|Param|null, // Default: "_username"
 *             password_parameter?: scalar|Param|null, // Default: "_password"
 *             csrf_parameter?: scalar|Param|null, // Default: "_csrf_token"
 *             csrf_token_id?: scalar|Param|null, // Default: "authenticate"
 *             enable_csrf?: bool|Param, // Default: false
 *             post_only?: bool|Param, // Default: true
 *             form_only?: bool|Param, // Default: false
 *             always_use_default_target_path?: bool|Param, // Default: false
 *             default_target_path?: scalar|Param|null, // Default: "/"
 *             target_path_parameter?: scalar|Param|null, // Default: "_target_path"
 *             use_referer?: bool|Param, // Default: false
 *             failure_path?: scalar|Param|null, // Default: null
 *             failure_forward?: bool|Param, // Default: false
 *             failure_path_parameter?: scalar|Param|null, // Default: "_failure_path"
 *             service?: scalar|Param|null, // Default: "ldap"
 *             dn_string?: scalar|Param|null, // Default: "{user_identifier}"
 *             query_string?: scalar|Param|null,
 *             search_dn?: scalar|Param|null, // Default: ""
 *             search_password?: scalar|Param|null, // Default: ""
 *         },
 *         json_login?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             check_path?: scalar|Param|null, // Default: "/login_check"
 *             use_forward?: bool|Param, // Default: false
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             username_path?: scalar|Param|null, // Default: "username"
 *             password_path?: scalar|Param|null, // Default: "password"
 *         },
 *         json_login_ldap?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             check_path?: scalar|Param|null, // Default: "/login_check"
 *             use_forward?: bool|Param, // Default: false
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             username_path?: scalar|Param|null, // Default: "username"
 *             password_path?: scalar|Param|null, // Default: "password"
 *             service?: scalar|Param|null, // Default: "ldap"
 *             dn_string?: scalar|Param|null, // Default: "{user_identifier}"
 *             query_string?: scalar|Param|null,
 *             search_dn?: scalar|Param|null, // Default: ""
 *             search_password?: scalar|Param|null, // Default: ""
 *         },
 *         access_token?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             realm?: scalar|Param|null, // Default: null
 *             token_extractors?: Param|string|list<scalar|Param|null>,
 *             token_handler?: Param|string|array{
 *                 id?: scalar|Param|null,
 *                 oidc_user_info?: Param|string|array{
 *                     base_uri?: scalar|Param|null, // Base URI of the userinfo endpoint on the OIDC server, or the OIDC server URI to use the discovery (require "discovery" to be configured). The latter must end with a slash when it has a path, as the discovery path is resolved against it as a relative URL.
 *                     discovery?: array{ // Enable the OIDC discovery.
 *                         cache?: array{
 *                             id?: scalar|Param|null, // Cache service id to use to cache the OIDC discovery configuration.
 *                         },
 *                     },
 *                     claim?: scalar|Param|null, // Claim which contains the user identifier (e.g. sub, email, etc.). // Default: "sub"
 *                     client?: scalar|Param|null, // HttpClient service id to use to call the OIDC server.
 *                 },
 *                 oidc?: array{
 *                     discovery?: array{ // Enable the OIDC discovery.
 *                         base_uri?: Param|string|list<scalar|Param|null>,
 *                         cache?: array{
 *                             id?: scalar|Param|null, // Cache service id to use to cache the OIDC discovery configuration.
 *                         },
 *                         enforce_key_usage_verification?: bool|Param, // When enabled (default), only keys explicitly designated for signature (via "use":"sig" or a "key_ops" entry containing "sign"/"verify") are accepted. When disabled, keys without any usage designation are also accepted; keys explicitly restricted to encryption are still rejected. // Default: true
 *                     },
 *                     claim?: scalar|Param|null, // Claim which contains the user identifier (e.g.: sub, email..). // Default: "sub"
 *                     audience?: scalar|Param|null, // Audience set in the token, for validation purpose.
 *                     issuers?: list<scalar|Param|null>,
 *                     algorithms?: list<scalar|Param|null>,
 *                     keyset?: scalar|Param|null, // JSON-encoded JWKSet used to sign the token (must contain a list of valid public keys).
 *                     encryption?: bool|array{
 *                         enabled?: bool|Param, // Default: false
 *                         enforce?: bool|Param, // When enabled, the token shall be encrypted. // Default: false
 *                         algorithms?: list<scalar|Param|null>,
 *                         keyset?: scalar|Param|null, // JSON-encoded JWKSet used to decrypt the token (must contain a list of valid private keys).
 *                     },
 *                 },
 *                 cas?: array{
 *                     validation_url?: scalar|Param|null, // CAS server validation URL
 *                     prefix?: scalar|Param|null, // CAS prefix // Default: "cas"
 *                     http_client?: scalar|Param|null, // HTTP Client service // Default: null
 *                 },
 *                 oauth2?: scalar|Param|null,
 *             },
 *         },
 *         http_basic?: array{
 *             provider?: scalar|Param|null,
 *             realm?: scalar|Param|null, // Default: "Secured Area"
 *         },
 *         http_basic_ldap?: array{
 *             provider?: scalar|Param|null,
 *             realm?: scalar|Param|null, // Default: "Secured Area"
 *             service?: scalar|Param|null, // Default: "ldap"
 *             dn_string?: scalar|Param|null, // Default: "{user_identifier}"
 *             query_string?: scalar|Param|null,
 *             search_dn?: scalar|Param|null, // Default: ""
 *             search_password?: scalar|Param|null, // Default: ""
 *         },
 *         remember_me?: array{
 *             secret?: scalar|Param|null, // Default: "%kernel.secret%"
 *             service?: scalar|Param|null,
 *             user_providers?: Param|string|list<scalar|Param|null>,
 *             catch_exceptions?: bool|Param, // Default: true
 *             signature_properties?: list<scalar|Param|null>,
 *             token_provider?: Param|string|array{
 *                 service?: scalar|Param|null, // The service ID of a custom remember-me token provider.
 *                 doctrine?: bool|array{
 *                     enabled?: bool|Param, // Default: false
 *                     connection?: scalar|Param|null, // Default: null
 *                 },
 *             },
 *             token_verifier?: scalar|Param|null, // The service ID of a custom rememberme token verifier.
 *             name?: scalar|Param|null, // Default: "REMEMBERME"
 *             lifetime?: int|Param, // Default: 31536000
 *             path?: scalar|Param|null, // Default: "/"
 *             domain?: scalar|Param|null, // Default: null
 *             secure?: true|false|"auto"|Param, // Defaults to the value of "framework.session.cookie_secure", or to "auto".
 *             httponly?: bool|Param, // Default: true
 *             samesite?: null|"lax"|"strict"|"none"|Param, // Defaults to the value of "framework.session.cookie_samesite", or to "lax".
 *             always_remember_me?: bool|Param, // Default: false
 *             remember_me_parameter?: scalar|Param|null, // Default: "_remember_me"
 *         },
 *     }>,
 *     access_control?: list<array{ // Default: []
 *         request_matcher?: scalar|Param|null, // Default: null
 *         requires_channel?: scalar|Param|null, // Default: null
 *         path?: scalar|Param|null, // Use the urldecoded format. // Default: null
 *         host?: scalar|Param|null, // Default: null
 *         port?: int|Param, // Default: null
 *         ips?: Param|string|list<scalar|Param|null>,
 *         attributes?: array<string, scalar|Param|null>,
 *         route?: scalar|Param|null, // Default: null
 *         methods?: Param|string|list<scalar|Param|null>,
 *         allow_if?: scalar|Param|null, // Default: null
 *         roles?: Param|string|list<scalar|Param|null>,
 *     }>,
 *     role_hierarchy?: array<string, Param|string|list<scalar|Param|null>>,
 * }
 * @psalm-type TwigConfig = array{
 *     form_themes?: list<scalar|Param|null>,
 *     globals?: array<string, array{ // Default: []
 *         id?: scalar|Param|null,
 *         type?: scalar|Param|null,
 *         value?: mixed,
 *         ...<string, mixed>
 *     }>,
 *     autoescape_service?: scalar|Param|null, // Default: null
 *     autoescape_service_method?: scalar|Param|null, // Default: null
 *     cache?: scalar|Param|null, // Default: true
 *     charset?: scalar|Param|null, // Default: "%kernel.charset%"
 *     debug?: bool|Param, // Default: "%kernel.debug%"
 *     strict_variables?: bool|Param, // Default: "%kernel.debug%"
 *     auto_reload?: scalar|Param|null,
 *     optimizations?: int|Param,
 *     default_path?: scalar|Param|null, // The default path used to load templates. // Default: "%kernel.project_dir%/templates"
 *     file_name_pattern?: Param|string|list<scalar|Param|null>,
 *     paths?: array<string, mixed>,
 *     date?: array{ // The default format options used by the date filter.
 *         format?: scalar|Param|null, // Default: "F j, Y H:i"
 *         interval_format?: scalar|Param|null, // Default: "%d days"
 *         timezone?: scalar|Param|null, // The timezone used when formatting dates, when set to null, the timezone returned by date_default_timezone_get() is used. // Default: null
 *     },
 *     number_format?: array{ // The default format options for the number_format filter.
 *         decimals?: int|Param, // Default: 0
 *         decimal_point?: scalar|Param|null, // Default: "."
 *         thousands_separator?: scalar|Param|null, // Default: ","
 *     },
 *     mailer?: array{
 *         html_to_text_converter?: scalar|Param|null, // A service implementing the "Symfony\Component\Mime\HtmlToTextConverter\HtmlToTextConverterInterface". // Default: null
 *     },
 * }
 * @psalm-type NowoTimeTrackConfig = array{
 *     user_class?: scalar|Param|null, // FQCN of the application user entity (must implement UserInterface and expose getId()).
 *     accepted_user_classes?: list<scalar|Param|null>,
 *     user_mapping?: "relation"|"id"|Param, // Since 1.23.0 (REQ-ARCH-001 rule 2): how the bundle rows store their user. relation (default, the 1.22 behaviour): Doctrine relations to user_class (foreign keys to your users table). id: the user class and id in a bundle-owned row (table <table_prefix>host_users, string id, binary collation on MySQL / MariaDB); user_class need not be a Doctrine entity of the bundle entity manager. // Default: "relation"
 *     service_account_policy?: scalar|Param|null, // Since 1.25.0 (Beacon spec 234): service id implementing Integration\ServiceAccountPolicyInterface (isServiceAccount(TimeTrackUserRef): bool). Null (default): NoServiceAccountPolicy, everyone is a person. A service account (agent) may have overlapping entries; its entries are marked service_account. // Default: null
 *     table_prefix?: scalar|Param|null, // Prefix for time track tables (entries, active_timers, client_tokens). // Default: "time_track_"
 *     task_provider?: scalar|Param|null, // Service id implementing Integration\TaskDirectoryInterface (optionally BatchTaskDirectoryInterface). Defaults to StubTaskProvider for demos. // Default: "Nowo\\TimeTrackBundle\\Bridge\\StubTaskProvider"
 *     team_context_provider?: scalar|Param|null, // Service id implementing Integration\TeamDirectoryInterface. // Default: "Nowo\\TimeTrackBundle\\Bridge\\NullTeamContextProvider"
 *     route_prefix?: scalar|Param|null, // Default: ""
 *     database?: array{
 *         entity_manager?: scalar|Param|null, // Default: "default"
 *         mapping_type?: "attribute"|"xml"|Param, // Since 1.23.0 (REQ-ARCH-001 rule 2): how the bundle entities are mapped: attribute (default) or xml (src/Resources/config/doctrine, the same mapping, for hosts that map everything in XML). // Default: "attribute"
 *         private_entity_manager?: bool|Param|null, // Deprecated: The "nowo_time_track.database.private_entity_manager.private_entity_manager" option has no effect since 2.0 (the private entity manager is the only mode); remove it. // Since 2.0 the bundle rows are always written and read through a private entity manager created on the connection and mapping of `entity_manager` (REQ-ARCH-001 rule 6). `true` has no effect (deprecated, removed in 3.0); `false` fails the container build (see UPGRADING "To 2.0.0"). // Default: null
 *     },
 *     security?: array{
 *         access_checker?: scalar|Param|null, // Default: null
 *         admin_roles?: list<scalar|Param|null>,
 *         access_roles?: list<scalar|Param|null>,
 *         allow_unauthenticated?: bool|Param, // Default: false
 *         manager_can_view_entries?: bool|Param, // Default: true
 *         manager_can_edit_entries?: bool|Param|null, // Deprecated: The "nowo_time_track.security.manager_can_edit_entries.manager_can_edit_entries" option has no effect since 2.0; remove it and decide edit access in a "nowo_time_track.time_entry.access_check" listener (TimeEntryAccessCheckEvent). // Removed in 2.0 (deprecated since 1.5): team managers may edit entries in TeamAccessGuard::canEditEntry(); decide edit access in a "nowo_time_track.time_entry.access_check" listener (TimeEntryAccessCheckEvent). false fails the container build, true has no effect (removed in 3.0). // Default: null
 *     },
 *     routes?: array{
 *         index?: array{
 *             path?: scalar|Param|null, // Default: "/tools/time-track"
 *             name?: scalar|Param|null, // Default: "nowo_time_track_index"
 *         },
 *         reports?: array{
 *             path?: scalar|Param|null, // Default: "/tools/time-track/reports"
 *             name?: scalar|Param|null, // Default: "nowo_time_track_reports"
 *         },
 *         device_verify?: array{ // Default device verification page (1.9; only routed when client_api.device_flow.enabled and default_page are true). Session-authenticated: keep it behind the host firewall.
 *             path?: scalar|Param|null, // Default: "/tools/time-track/device"
 *             name?: scalar|Param|null, // Default: "nowo_time_track_device_verify"
 *         },
 *     },
 *     reports?: array{ // Reports page of the manage UI (REQ-PERF-001).
 *         page_size?: int|Param, // Entries per reports page (query parameter `page`). // Default: 50
 *     },
 *     imports?: array{ // Since 1.11: imported time entries (source "import", written by Import\ImportedTimeEntryWriter). Descriptions are kept in full (no length cap).
 *         max_entry_seconds?: int|Param, // Longest single imported entry, in seconds (default 31 days); longer entries are refused. // Default: 2678400
 *     },
 *     working_time?: array{
 *         enabled?: bool|Param, // Hours balance, overtime and the balance client API (default off). // Default: false
 *         calendar_provider?: scalar|Param|null, // Service id implementing WorkingTime\WorkingCalendarSourceInterface (null = DerivedWorkingCalendar from the 1.12 working hours and days off). // Default: null
 *         worked_time_source?: scalar|Param|null, // Service id implementing WorkingTime\WorkedTimeRecordInterface (null = TaskTimeWorkedTimeSource: live task time, legalRecord false). // Default: null
 *         overtime_policy?: scalar|Param|null, // Service id implementing OvertimePolicyInterface (null = ConfigOvertimePolicy with the values below). // Default: null
 *         balance?: array{
 *             start_date?: scalar|Param|null, // First day of the running balance (Y-m-d); null = the 366 days before the end of the asked range, or after the last locked period. // Default: null
 *         },
 *         overtime?: array{
 *             daily_mode?: "expected"|"ordinary_max"|"none"|Param, // expected: above the day's expected time; ordinary_max: above the agreement cap or ordinary_max_seconds; none: weekly rule only (flexible hours). // Default: "expected"
 *             ordinary_max_seconds?: int|Param, // 9 h, art. 34.3 ET. // Default: 32400
 *             weekly_mode?: "expected"|"legal_max"|Param, // expected: above the week's expected time; legal_max: above weekly_max_seconds. // Default: "expected"
 *             weekly_max_seconds?: int|Param, // 40 h, art. 34.1 ET. // Default: 144000
 *             annual_cap_seconds?: int|Param, // 80 h, art. 35.2 ET (0 = no cap flags). // Default: 288000
 *             ratify_on_approval?: bool|Param, // Write one overtime_ratified ledger line when a timesheet is approved. // Default: true
 *         },
 *     },
 *     timesheets?: array{
 *         enabled?: bool|Param, // Timesheets, period lock and the timesheet client API (default off). // Default: false
 *         scope_mode?: "provider"|"single"|Param, // provider: tenant scopes from scope_provider; single: one scope "" for everybody. // Default: "provider"
 *         scope_provider?: scalar|Param|null, // Service id implementing Timesheet\TimesheetScopePolicyInterface (required with scope_mode: provider). // Default: null
 *         approver_provider?: scalar|Param|null, // Service id implementing Timesheet\TimesheetApproverPolicyInterface (required when enabled). // Default: null
 *         period?: "week"|"month"|Param, // week (ISO, Monday to Sunday) or month. // Default: "week"
 *         default_timezone?: scalar|Param|null, // Zone a period is cut in when neither the scope nor the working hours give one. // Default: "UTC"
 *         submit_requires_period_end?: bool|Param, // Refuse a submit before the period has ended (default false: a Friday afternoon submit is allowed). // Default: false
 *         auto_lock_after_days?: int|Param, // Lock every period N days after its end (nowo:time-track:timesheets:auto-lock); null = off. // Default: null
 *         allow_self_approval?: bool|Param, // Fixed false in 1.13: nobody approves their own timesheet, admin roles included. // Default: false
 *         admin_roles_view?: bool|Param, // Whether security.admin_roles see timesheets of their scopes without being approvers (default false). // Default: false
 *         reopen_roles?: list<scalar|Param|null>,
 *         approver_sees_notes?: bool|Param, // Whether approvers see entry notes and descriptions (default false: minimisation). // Default: false
 *         audit_blocked_edits?: bool|Param, // Write an edit_blocked audit row per refused write (at most one per timesheet per minute). // Default: false
 *         retention_years?: int|Param, // Years timesheets, their audit rows and the ledger are kept after the end of the period month (4-10). // Default: 4
 *     },
 *     calendar?: array{
 *         enabled?: bool|Param, // Calendar suggestions, their client API and the sync command (default off). // Default: false
 *         credential_provider?: scalar|Param|null, // Service id implementing Calendar\CalendarCredentialSourceInterface (host OAuth tokens; required for server mode). // Default: null
 *         task_key_resolver?: scalar|Param|null, // Service id implementing Calendar\TaskKeyLookupInterface (host task keys; required when enabled). // Default: null
 *         providers?: list<"google"|"microsoft"|Param>,
 *         modes?: list<"server"|"local"|Param>,
 *         lookback_days?: int|Param, // Past days read per sync (events end before now; future events are never suggested). // Default: 14
 *         suggestion_ttl_days?: int|Param, // Days a suggestion is kept after its event ends. // Default: 14
 *         sync_interval_minutes?: int|Param, // Minutes between two scheduled syncs of one connection. // Default: 60
 *         max_event_seconds?: int|Param, // Longer events are ignored (default 8 h). // Default: 28800
 *         max_rules_per_user?: int|Param, // User rules per scope. // Default: 20
 *         max_pages?: int|Param, // Provider pages read per sync. // Default: 10
 *         microsoft_scope?: "Calendars.ReadBasic"|"Calendars.Read"|Param, // Since 1.19: granted Graph scope — Calendars.ReadBasic (no body: task keys from titles) or Calendars.Read (body too). // Default: "Calendars.ReadBasic"
 *         http_client?: scalar|Param|null, // Since 1.20 (audit ML-L1): service id of the HTTP client of the calendar readers and OAuth token calls (default: the framework http_client). Point it at an untraced client where the profiler is on. // Default: "http_client"
 *         oauth?: array{ // Since 1.19: OAuth apps of the optional OAuth helpers (empty = not configured). Use %env()% for the secrets.
 *             app_provider?: scalar|Param|null, // Since 1.20: service id implementing CalendarOAuthAppProviderInterface (apps read at runtime on every call, e.g. encrypted in the database); null = the static google / microsoft values below. // Default: null
 *             google?: array{
 *                 client_id?: scalar|Param|null, // Default: ""
 *                 client_secret?: scalar|Param|null, // Default: ""
 *             },
 *             microsoft?: array{
 *                 client_id?: scalar|Param|null, // Default: ""
 *                 client_secret?: scalar|Param|null, // Default: ""
 *                 tenant?: scalar|Param|null, // common, organizations, consumers or a tenant id / domain. // Default: "common"
 *             },
 *         },
 *     },
 *     manual_entries?: array{
 *         enabled?: bool|Param, // POST entries on the client API (default off). Accepting calendar suggestions does not depend on it. // Default: false
 *         max_age_days?: int|Param, // Oldest accepted start, in days before now. // Default: 31
 *     },
 *     entries?: array{
 *         allow_without_task?: bool|Param, // Since 1.36.0: false (default) = every timer and entry needs a task (or, since 2.1.0, a subject), as before. true = time with neither task nor subject is accepted, with an optional free-text description and a tenant scope that must be one of the user's. Such an entry is reached only by its owner and, for anyone else, within their own tenant scopes; never through task or board permissions. Since 2.1.0 the client API also accepts it on timer/start when require_allocation is off. // Default: false
 *         require_allocation?: bool|Param, // Since 2.1.0 (Beacon spec 256): true = an interactive stop (client API timer/stop, HostTimerApi::stop(), the web UI) or a manual entry with neither task nor subject is refused (409 allocation_required, the timer keeps running). Stops the bundle triggers itself (start-replacing, switch, idle resolution, user deletion) are never refused: they store the entry unallocated and dispatch TimeEntryUnallocatedEvent. Default false. // Default: false
 *         require_activity_type?: bool|Param, // Since 2.1.0: true = an interactive stop or a manual entry without an activity type is refused (activity_type_required). Effective only with an activity type provider (ActivityTypeProviderInterface). Default false. // Default: false
 *         subjects?: array{ // Since 2.1.0 (Beacon spec 256): time subjects (customers, the organisation, ...). Implement Nowo\TimeTrackBundle\Subject\TimeSubjectProviderInterface once per subject type; autoconfiguration registers it.
 *             scope_subject?: array{ // The built-in subject "the tenant itself" over the timesheet scope provider (internal time on the organisation). Off by default.
 *                 enabled?: bool|Param, // Default: false
 *                 type?: scalar|Param|null, // Subject type of the built-in provider (^[a-z][a-z0-9_]{0,31}$). // Default: "organisation"
 *             },
 *             search?: array{
 *                 max_per_minute?: int|Param, // Client API GET subjects/search requests per token and minute. // Default: 60
 *             },
 *         },
 *     },
 *     meetings?: array{
 *         enabled?: bool|Param, // GET me/meetings and the GET me meeting keys (default off). // Default: false
 *         provider?: scalar|Param|null, // Service id implementing Meeting\MeetingSourceInterface (required when enabled). // Default: null
 *         max_range_days?: int|Param, // Longest from-to range. // Default: 14
 *         max_past_days?: int|Param, // How far back from may go (host retention: 7 days). // Default: 1
 *         default_limit?: int|Param, // Default: 100
 *         refresh_after_seconds?: int|Param, // Default poll-gap hint to the clients. // Default: 300
 *         rate_limit?: array{
 *             per_hour?: int|Param, // Default: 60
 *             per_minute?: int|Param, // Default: 6
 *         },
 *         jitsi_hosts?: list<scalar|Param|null>,
 *         links_calendars_route?: scalar|Param|null, // Host route name of Account → Integrations → Calendars (GET me links.calendars; https only). // Default: null
 *     },
 *     retention?: array{ // Since 1.11: retention policy (Retention\TimeEntryRetentionPolicy) that any purge must apply.
 *         include_imported?: bool|Param, // false (default) = imported entries are history and stay out of retention purges. // Default: false
 *         anonymise_on_user_delete?: bool|Param, // Since 1.15. false (default) = a user removed through the entity manager leaves their entries under their name. true = the hours stay and the person goes, as with FormerPersonTimeEntries::anonymiseUser(). // Default: false
 *         former_person_listener?: bool|Param, // Since 1.19. true (default) = FormerPersonSnapshotListener reacts to user_class removals on the entity manager. false = no listener on your entity manager (DDD hosts that delete users through their own flow call FormerPersonTimeEntries::release() / anonymiseUser() themselves). // Default: true
 *     },
 *     web_ui?: array{ // Manage UI pages on the UiKit page contract (REQ-UI-003).
 *         layout_template?: scalar|Param|null, // Layout of the pages (passed to the UiKit scaffolds as ListPage::$layout). null = nowo_ui_kit.layouts.admin. Since 2.0 a layout without the UiKit page header block fails the page (the 1.x bridge is removed). // Default: null
 *         templates?: array{ // Per-page template override: a host template that extends @!NowoTimeTrackBundle/<template> and overrides a data block.
 *             index?: scalar|Param|null, // Default: "@NowoTimeTrackBundle/time_track/index.html.twig"
 *             reports?: scalar|Param|null, // Default: "@NowoTimeTrackBundle/time_track/reports.html.twig"
 *         },
 *     },
 *     clients?: array{ // Browser extension and Tauri desktop agent API (Bearer token auth).
 *         enabled?: bool|Param, // Default: false
 *         token_ttl?: int|Param, // Bearer token lifetime in seconds. // Default: 86400
 *         idle_threshold_seconds?: int|Param, // Desktop idle detection threshold in seconds. // Default: 300
 *         authenticator?: scalar|Param|null, // Service id implementing ClientAuthenticatorInterface. // Default: null
 *         user_provider?: scalar|Param|null, // Symfony user provider for default password authenticator. // Default: null
 *         cors_allowed_origins?: list<scalar|Param|null>,
 *         cors_allow_any_extension_origin?: bool|Param|null, // Deprecated: The "nowo_time_track.clients.cors_allow_any_extension_origin.cors_allow_any_extension_origin" option has no effect since 2.0 (the client API allows exact origins only); remove it. // Removed in 2.0 (deprecated since 1.9): true fails the container build, false has no effect (removed in 3.0). List the exact extension origins in client_api.allowed_origins. // Default: null
 *         login_rate_limit?: array{
 *             enabled?: bool|Param, // Default: true
 *             cache_pool?: scalar|Param|null, // Default: "cache.app"
 *             max_attempts?: int|Param, // Default: 10
 *             interval_seconds?: int|Param, // Default: 900
 *             max_attempts_per_ip?: int|Param, // Failed logins per client IP (any username) within interval_seconds. null = 5 × max_attempts. // Default: null
 *             max_attempts_per_username?: int|Param, // Failed logins per username (any client IP) within interval_seconds. null = 5 × max_attempts. // Default: null
 *         },
 *         routes?: array{
 *             login?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/login"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_login"
 *             },
 *             logout?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/logout"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_logout"
 *             },
 *             me?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/me"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_me"
 *             },
 *             tasks?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/tasks"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_tasks"
 *             },
 *             timer?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/timer"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_timer"
 *             },
 *             timer_start?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/timer/start"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_timer_start"
 *             },
 *             timer_stop?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/timer/stop"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_timer_stop"
 *             },
 *             entries?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/entries"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_entries"
 *             },
 *             timer_switch?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/timer/switch"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_timer_switch"
 *             },
 *             timer_discard_idle?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/timer/discard-idle"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_timer_discard_idle"
 *             },
 *             export?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/export"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_export"
 *             },
 *             device_code?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/device/code"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_device_code"
 *             },
 *             device_token?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/device/token"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_device_token"
 *             },
 *             workday?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/workday"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_workday"
 *             },
 *             workday_action?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/workday/{action}"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_workday_action"
 *             },
 *             balance?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/balance"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_balance"
 *             },
 *             timesheets?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/timesheets"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_timesheets"
 *             },
 *             timesheet_submit?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/timesheets/{periodKey}/submit"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_timesheet_submit"
 *             },
 *             timesheet_withdraw?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/timesheets/{periodKey}/withdraw"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_timesheet_withdraw"
 *             },
 *             calendar_connections?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/calendar/connections"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_calendar_connections"
 *             },
 *             calendar_connection_revoke?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/calendar/connections/{id}"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_calendar_connection_revoke"
 *             },
 *             suggestions?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/suggestions"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_suggestions"
 *             },
 *             suggestions_accept?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/suggestions/accept"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_suggestions_accept"
 *             },
 *             suggestions_dismiss?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/suggestions/dismiss"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_suggestions_dismiss"
 *             },
 *             suggestions_resolve_keys?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/suggestions/resolve-keys"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_suggestions_resolve_keys"
 *             },
 *             entries_create?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/entries"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_entries_create"
 *             },
 *             entries_update?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/entries/{id}"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_entries_update"
 *             },
 *             me_meetings?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/me/meetings"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_client_me_meetings"
 *             },
 *             monitoring_acknowledge?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/monitoring/acknowledge"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_monitoring_acknowledge"
 *             },
 *             monitoring_screenshot_intent?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/monitoring/screenshots/intent"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_monitoring_screenshot_intent"
 *             },
 *             monitoring_upload?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/monitoring/uploads/{uploadId}"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_monitoring_upload"
 *             },
 *             monitoring_screenshot_discarded?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/monitoring/screenshots/discarded"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_monitoring_screenshot_discarded"
 *             },
 *             monitoring_screenshot_delete?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/monitoring/screenshots/{screenshotId}"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_monitoring_screenshot_delete"
 *             },
 *             monitoring_activity?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/monitoring/activity"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_monitoring_activity"
 *             },
 *             timer_allocate?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/timer/allocate"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_timer_allocate"
 *             },
 *             subjects_search?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/subjects/search"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_subjects_search"
 *             },
 *             activity_types?: array{
 *                 path?: scalar|Param|null, // Default: "/api/time-track/activity-types"
 *                 name?: scalar|Param|null, // Default: "nowo_time_track_api_activity_types"
 *             },
 *             ...<string, mixed>
 *         },
 *     },
 *     client_api?: array{ // Client API 1.9 (contract v0.2): host interfaces, CORS, metadata, rate limits, audit and device flow. Needs clients.enabled.
 *         prefix?: scalar|Param|null, // Since 1.29 (REQ-API-001): host API prefix of the versioned client routes, served at <route_prefix><prefix>/time-track/v1/… (default /api → /api/time-track/v1/…). The unversioned clients.routes.* paths stay as permanent aliases. Empty string or a path starting with "/" and not ending with "/". // Default: "/api"
 *         routes?: array{ // Since 1.30: which client API routes the route import publishes.
 *             include?: list<scalar|Param|null>,
 *         },
 *         user_checker?: Param|string|array{ // Since 1.29 (Beacon C5): run a Symfony user checker (checkPreAuth + checkPostAuth) for the user of every Bearer token use; a disabled, locked or expired account gets 401 invalid_token. On by default. `false` turns it off; a string is the checker service id.
 *             enabled?: bool|Param, // Default: true
 *             service?: scalar|Param|null, // Explicit user checker service id (Symfony UserCheckerInterface); wins over firewall. Null = security.user_checker.<firewall> (see firewall); none found = no check. // Default: null
 *             firewall?: scalar|Param|null, // Firewall whose user checker (security.user_checker.<firewall>, incl. chained checkers such as UserKit) runs. Null = the firewall named "main" when present, else the first firewall with security enabled. // Default: null
 *             revoke_tokens?: bool|Param, // Delete the refused token (Beacon ActiveAccountClientTokens behaviour): the client signs in again after the account is re-enabled. false keeps it, so the token works again once the account is re-enabled. // Default: true
 *             required?: bool|Param, // Fail the container build when the resolved checker refuses nobody: none found, or Symfony's default security.user_checker (InMemoryUserChecker, which ignores entity users). false (default): the build logs it and sets %nowo_time_track.client_api.user_checker.effective% to false. // Default: false
 *             ...<string, mixed>
 *         },
 *         allowed_origins?: list<scalar|Param|null>,
 *         allow_tauri_webview_origins?: bool|Param|null, // Deprecated: The "nowo_time_track.client_api.allow_tauri_webview_origins.allow_tauri_webview_origins" option has no effect since 2.0 (the client API no longer allows the Tauri webview origins); remove it. // Removed in 2.0: the client API no longer allows the Tauri webview origins of the 1.x desktop agent. true fails the container build, false has no effect (removed in 3.0). // Default: null
 *         allow_metadata?: bool|Param, // Accept free client "metadata" on timer/start (default false: rejected with 400 metadata_not_allowed; clients send source + note). // Default: false
 *         settings_provider?: scalar|Param|null, // Service id implementing ClientApiSettingsProviderInterface (null = these config values). // Default: null
 *         credential_state?: scalar|Param|null, // Service id implementing ClientCredentialStateInterface, checked on every request (null = tokens stay valid until expiry / logout). // Default: null
 *         credential_issuer?: scalar|Param|null, // Service id implementing Client\ClientCredentialMinterInterface, called when a device is approved (null = opaque device:<uuid> ids). // Default: null
 *         task_access_checker?: scalar|Param|null, // Service id implementing Security\TaskAccessPolicyInterface, checked on timer/start and timer/switch (null = the task provider decides). // Default: null
 *         profile_provider?: scalar|Param|null, // Service id implementing Client\ClientUserProfileSourceInterface (displayName / workingHours of GET me; null = none). // Default: null
 *         workday_provider?: scalar|Param|null, // Since 1.13: service id implementing Workday\WorkdaySourceInterface (optionally WorkdayHistorySourceInterface; R-GDPR §8; null = no workday API). // Default: null
 *         links_working_time_route?: scalar|Param|null, // Since 1.19 (spec 198): host route name of the working-time page (GET me links.workingTime, only while features.workday is true; https only). // Default: null
 *         leave_provider?: scalar|Param|null, // Since 1.22 (spec 204): service id implementing Client\ClientUserLeaveProviderInterface (GET workday leave, GET me features.leave / links.leave; null = no leave keys, as in 1.21). // Default: null
 *         links_leave_route?: scalar|Param|null, // Since 1.22 (spec 204): host route name of the Leave page (GET me links.leave, sent only with a leave_provider; https only). // Default: null
 *         backdate?: array{ // Client-supplied startedAt (timer/start) and endedAt (timer/stop), 1.10: clamped and reported, never rejected.
 *             max_seconds?: int|Param, // How far back startedAt may go (default and maximum 24 h; 0 = startedAt / endedAt ignored, reason "disabled"). // Default: 86400
 *             clock_skew_seconds?: int|Param, // Future tolerance read as "now" (beyond it the value is clamped with reason "future"). // Default: 120
 *         },
 *         idle?: array{
 *             allow_reassign?: bool|Param, // Accept timer/discard-idle action "reassign" (1.10; false = 400 invalid_request and features.idle_reassign false). // Default: true
 *         },
 *         idle_check?: array{ // Idle-check settings published on GET me (1.10). The server only receives the user's decision (timer/discard-idle); no activity counter is sent or stored.
 *             enabled?: bool|Param, // Default: true
 *             threshold_minutes?: int|Param, // Idle minutes before the client asks. // Default: 10
 *             answer_timeout_minutes?: int|Param, // Minutes the idle prompt waits for an answer. // Default: 5
 *             user_can_disable?: bool|Param, // Whether users may turn the idle check off in the client (1.10.1; limits.idle_check.user_can_disable). // Default: false
 *             provider?: scalar|Param|null, // Service id implementing Client\IdleCheckSettingsPolicyInterface (per-user settings; null = these values). Its values are clamped to the same ranges. // Default: null
 *         },
 *         task_url?: array{
 *             origin?: scalar|Param|null, // Expected origin of task URLs on tasks / timer (exact https://host[:port]; null = the request origin). Behind a proxy set it from the public URL, e.g. "%beacon.app_url%". // Default: null
 *         },
 *         rate_limit?: array{
 *             cache_pool?: scalar|Param|null, // PSR-6 pool shared by all workers (Redis, filesystem, APCu; not an array pool). // Default: "cache.app"
 *             per_token?: int|Param, // Requests per token per window (0 = off). // Default: 60
 *             per_ip?: int|Param, // Requests per client IP per window on every API route (0 = off). // Default: 300
 *             device_per_ip?: int|Param, // Requests per client IP per window on device/code and device/token (0 = off). // Default: 30
 *             window_seconds?: int|Param, // Default: 60
 *         },
 *         audit?: array{
 *             denial_interval_seconds?: int|Param, // At most one request_denied event per credential (or IP prefix) and reason per interval. // Default: 60
 *         },
 *         monitoring?: array{ // Since 1.26 (contract §24, spec 199): activity monitoring, opt-in. Off (no GET me block, every monitoring route 404) until policy_source and evidence_store are set. Organisations enable it only with a recorded DPIA and the notice shown first.
 *             policy_source?: scalar|Param|null, // Service id implementing Monitoring\MonitoringPolicySourceInterface (null = monitoring off). // Default: null
 *             evidence_store?: scalar|Param|null, // Service id implementing Monitoring\MonitoringEvidenceStoreInterface (required with policy_source; the bundle never writes files). // Default: null
 *             acknowledgement_store?: scalar|Param|null, // Service id implementing Monitoring\MonitoringAcknowledgementStoreInterface (null = the bundle table, through the bundle entity manager; aliasing the interface works too). // Default: null
 *             signer?: scalar|Param|null, // Service id implementing Monitoring\MonitoringUploadSignerInterface (null = HMAC-SHA256 with a key derived from signing_key or kernel.secret). // Default: null
 *             signing_key?: scalar|Param|null, // Secret the default signer derives its key from (null = kernel.secret). Use an env var, e.g. "%env(TIME_TRACK_MONITORING_KEY)%". // Default: null
 *             upload_base_url?: scalar|Param|null, // Since 1.26 (audit PM-L2): exact origin "https://host[:port]" (one trailing "/" allowed) of the signed upload URLs, e.g. "%env(DEFAULT_URI)%" (checked at runtime: anything else answers 503); null = the request scheme and host, then set framework.trusted_hosts / trusted_proxies. // Default: null
 *             rate_limit?: array{ // Per token and 10-minute window (0 = off), on top of client_api.rate_limit.
 *                 intents_per_10_min?: int|Param, // POST screenshots/intent (and, each on its own counter, screenshots/discarded and DELETE screenshots/{id}). // Default: 6
 *                 uploads_per_10_min?: int|Param, // PUT uploads/{uploadId}. // Default: 6
 *                 activity_per_10_min?: int|Param, // POST activity. // Default: 30
 *             },
 *         },
 *         device_flow?: array{ // Device flow of the desktop app (RFC 8628 shape). Opt-in.
 *             enabled?: bool|Param, // Default: false
 *             default_page?: bool|Param, // Route the bundle verification page (routes.device_verify). false when the host renders its own page with DeviceAuthorizationService. // Default: true
 *             verification_uri?: scalar|Param|null, // Absolute URL of the verification page sent to the device (null = the default page URL; required when default_page is false). // Default: null
 *             code_ttl?: int|Param, // Default: 600
 *             interval?: int|Param, // Default: 5
 *             lookup_limit?: int|Param, // User code lookups per user per lookup_window_seconds. // Default: 10
 *             lookup_window_seconds?: int|Param, // Default: 900
 *             max_pending_per_prefix?: int|Param, // Device requests created per client IPv4 /24 or IPv6 /64 within code_ttl (0 = off). // Default: 20
 *             max_pending?: int|Param, // Pending, not expired device requests in total; device/code answers 429 above it (0 = off). // Default: 10000
 *         },
 *     },
 *     client_distribution?: array{ // Download / store links of the clients (ClientDistributionProviderInterface default). Absolute http(s) URLs only.
 *         provider?: scalar|Param|null, // Service id implementing ClientDistributionProviderInterface (null = these links). // Default: null
 *         extension?: array{
 *             chrome?: scalar|Param|null, // Default: null
 *             edge?: scalar|Param|null, // Default: null
 *             firefox?: scalar|Param|null, // Default: null
 *         },
 *         desktop?: array{
 *             windows?: scalar|Param|null, // Default: null
 *             macos?: scalar|Param|null, // Default: null
 *             linux?: scalar|Param|null, // Default: null
 *             latest_version?: scalar|Param|null, // Default: null
 *             update_manifest_url?: scalar|Param|null, // Default: null
 *         },
 *         min_supported_version?: array{ // Oldest supported client version per client type, returned by GET me as minSupportedVersion (semver; null = key absent).
 *             provider?: scalar|Param|null, // Service id implementing ClientMinSupportedVersionProviderInterface (null = these versions). // Default: null
 *             extension?: scalar|Param|null, // Default: null
 *             desktop?: scalar|Param|null, // Default: null
 *             web?: scalar|Param|null, // Default: null
 *         },
 *     },
 *     ...<string, mixed>
 * }
 * @psalm-type NowoTaskBoardConfig = array{
 *     user_directory?: scalar|Param|null, // Since 2.15.0: service id of a Nowo\TaskBoardBundle\User\TaskBoardUserDirectoryInterface (finds a host user by id). Required with database.user_reference: id. // Default: null
 *     user_class?: scalar|Param|null,
 *     table_prefix?: scalar|Param|null, // Default: "task_board_"
 *     team_membership_resolver?: scalar|Param|null, // Default: null
 *     route_prefix?: scalar|Param|null, // Default: ""
 *     dashboard_route?: scalar|Param|null, // Default: null
 *     firewall?: scalar|Param|null, // Deprecated: The "firewall" option at path "nowo_task_board.firewall" is deprecated and has no effect; configure the firewall and access_control in your security.yaml instead. // Deprecated: never read by the bundle. Protect the manage routes with your own security.yaml firewall/access_control. // Default: "main"
 *     api?: array{ // Since 2.2.0 (spec 213): a REST API shaped like ClickUp API v2. Off: no route, service, listener or table. See docs/API.md.
 *         enabled?: bool|Param, // Default: false
 *         prefix?: scalar|Param|null, // Base path of the API. Not affected by route_prefix or the tenant copies. // Default: "/api/tasks"
 *         host_decides_access?: bool|Param, // With security.resource_scoping: false every board is open unless a listener denies it. true says the host has those listeners: without it the API refuses to build. // Default: false
 *         quota_listener_required?: bool|Param, // Board and task creates through the API are refused unless a listener answers QUOTA_CHECK. false: a host with no plan limits. // Default: true
 *         max_body_bytes?: int|Param, // The largest JSON body. // Default: 1048576
 *         lists_include_folders?: bool|Param, // Since 2.19.0: whether GET /v2/space/{id}/list lists the boards in folders too by default (false: folderless only, as ClickUp; a request overrides it with include_folders=true|false). // Default: false
 *         hooks?: array{ // Service ids. Without them every default denies.
 *             authenticator?: scalar|Param|null, // Required with enabled: Api\Hook\TaskApiAuthenticatorInterface. // Default: null
 *             rate_limiter?: scalar|Param|null, // Api\Hook\TaskApiRateLimiterInterface. null: every request 429. // Default: null
 *             plan_gate?: scalar|Param|null, // Api\Hook\TaskApiPlanGateInterface. null: 403 PLAN_001. // Default: null
 *             workspace?: scalar|Param|null, // Api\Hook\TaskApiWorkspaceInterface. null: every board 404. // Default: null
 *             users?: scalar|Param|null, // Api\Hook\TaskApiUsersInterface. null: nobody is known. // Default: null
 *             comment_edit_policy?: scalar|Param|null, // Since 2.16.0: Api\Hook\TaskApiCommentEditPolicyInterface, who may edit a comment through PUT /v2/comment/{id}. null: its author only. // Default: null
 *             members?: scalar|Param|null, // Since 2.16.0: Api\Hook\TaskApiMembersInterface, the members of a team (/v2/team, /v2/team/{id}/member). null: none. // Default: null
 *             time_entries?: scalar|Param|null, // Api\Hook\TaskApiTimeEntriesInterface. null: 403 TIME_001. // Default: null
 *             attachments?: scalar|Param|null, // Api\Hook\TaskApiAttachmentsInterface. null: 403 FILE_001. // Default: null
 *             idempotency_store?: scalar|Param|null, // Api\Hook\TaskApiIdempotencyStoreInterface. // Default: null
 *         },
 *     },
 *     security?: array{
 *         access_checker?: scalar|Param|null, // Default: null
 *         access_roles?: list<scalar|Param|null>,
 *         create_roles?: list<scalar|Param|null>,
 *         list_roles?: list<scalar|Param|null>,
 *         allow_unauthenticated?: bool|Param, // Default: false
 *         resource_scoping?: bool|Param, // Restrict boards/tasks to their creator, team members, task members and manage_roles (IDOR protection). // Default: true
 *         manage_roles?: list<scalar|Param|null>,
 *     },
 *     routes?: array{
 *         index?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_index"
 *         },
 *         board?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board"
 *         },
 *         list?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/list"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_list"
 *         },
 *         gantt?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/gantt"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_gantt"
 *         },
 *         task?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task"
 *         },
 *         board_create?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/create"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_create"
 *         },
 *         task_create?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/create"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_create"
 *         },
 *         task_move?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/move"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_move"
 *         },
 *         task_advance?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/advance"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_advance"
 *         },
 *         task_link?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/link"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_link"
 *         },
 *         task_link_update?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/link/{linkId}"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_link_update"
 *         },
 *         task_link_remove?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/link/{linkId}/remove"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_link_remove"
 *         },
 *         task_member?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/member"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_member"
 *         },
 *         task_member_remove?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/member/{memberId}/remove"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_member_remove"
 *         },
 *         task_subtask?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/subtask"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_subtask"
 *         },
 *         task_priority?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/priority"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_priority"
 *         },
 *         column_create?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/column"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_column_create"
 *         },
 *         column_update?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/column/{columnId}"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_column_update"
 *         },
 *         column_reorder?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/columns/reorder"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_column_reorder"
 *         },
 *         board_import?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/import"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_import"
 *         },
 *         column_delete?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/column/{columnId}/delete"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_column_delete"
 *         },
 *         column_set_create?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/column-sets"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_column_set_create"
 *         },
 *         column_set_apply?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/column-sets/apply"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_column_set_apply"
 *         },
 *         column_set_delete?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/column-sets/{setId}/delete"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_column_set_delete"
 *         },
 *         task_panel?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/panel"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_panel"
 *         },
 *         task_comment?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/comment"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_comment"
 *         },
 *         task_comment_edit?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/comment/{commentId}"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_comment_edit"
 *         },
 *         task_comment_delete?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/comment/{commentId}/delete"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_comment_delete"
 *         },
 *         task_dependency?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/dependency"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_dependency"
 *         },
 *         task_import?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/import"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_import"
 *         },
 *         task_dependency_remove?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/dependency/{dependencyId}/remove"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_dependency_remove"
 *         },
 *         task_time?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/time"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_time"
 *         },
 *         task_time_remove?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/time/{entryId}/remove"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_time_remove"
 *         },
 *         task_time_billable?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/time/{entryId}/billable"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_time_billable"
 *         },
 *         task_by_number?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/n/{number}"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_by_number"
 *         },
 *         calendar?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/calendar"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_calendar"
 *         },
 *         task_schedule?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/schedule"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_schedule"
 *         },
 *         calendar_event_reschedule?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/calendar/event-reschedule"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_calendar_event_reschedule"
 *         },
 *         task_move_board?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/move-board"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_move_board"
 *         },
 *         task_duplicate?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/duplicate"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_duplicate"
 *         },
 *         task_recurrence?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/recurrence"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_recurrence"
 *         },
 *         task_recurrence_action?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/recurrence/{action}"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_recurrence_action"
 *         },
 *         task_question_ask?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/question"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_question_ask"
 *         },
 *         task_question_answer?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/question/{questionId}/answer"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_question_answer"
 *         },
 *         task_question_withdraw?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/question/{questionId}/withdraw"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_question_withdraw"
 *         },
 *         task_question_publish?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/question/{questionId}/publish"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_question_publish"
 *         },
 *         my_questions?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/questions"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_my_questions"
 *         },
 *         task_restore?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/task/{taskId}/restore"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_restore"
 *         },
 *         task_menu?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/menu/task"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_task_menu"
 *         },
 *         board_connection?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection"
 *         },
 *         board_connection_containers?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection/containers"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection_containers"
 *         },
 *         board_connection_link?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection/link"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection_link"
 *         },
 *         board_connection_refresh?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection/refresh"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection_refresh"
 *         },
 *         board_connection_progress?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection/progress/{runId}"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection_progress"
 *         },
 *         board_connection_cancel?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection/cancel/{runId}"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection_cancel"
 *         },
 *         board_connection_unlink?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection/unlink"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection_unlink"
 *         },
 *         board_connection_people?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection/people"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection_people"
 *         },
 *         board_connection_people_save?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection/people/save"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection_people_save"
 *         },
 *         board_connection_people_former?: array{
 *             path?: scalar|Param|null, // Default: "/tools/task-board/board/{boardId}/connection/people/former"
 *             name?: scalar|Param|null, // Default: "nowo_task_board_board_connection_people_former"
 *         },
 *     },
 *     web_ui?: array{ // Since 2.0.0: the UiKit page contract (REQ-UI-003).
 *         layout_template?: scalar|Param|null, // Layout the pages extend. null = nowo_ui_kit.layouts.admin. A pre-2.0 layout without the UiKit canonical blocks goes through a deprecated bridge. // Default: null
 *         templates?: array<string, scalar|Param|null>,
 *         context_menus?: scalar|Param|null, // Since 2.22.0: the context menu of the board cards, list rows and column headers (right-click, Shift+F10 or the ContextMenu key, a long press) opens the items of their kebab (UiKit 2.54 context menus). null follows nowo_ui_kit.theme.context_menus; true or false forces it on or off for TaskBoard's pages. The kebab itself is always there. // Default: null
 *     },
 *     templates?: array{
 *         layout?: scalar|Param|null, // Deprecated: The "nowo_task_board.templates.layout.layout" option is deprecated, use "nowo_task_board.web_ui.layout_template" instead. // Deprecated since 2.0.0: use web_ui.layout_template. // Default: null
 *         css_framework?: null|"bootstrap"|"bootstrap4"|"bootstrap5"|"tailwind"|"foundation"|"custom"|"tabler"|"none"|Param, // Deprecated: The "nowo_task_board.templates.css_framework.css_framework" option is deprecated and ignored, set "nowo_ui_kit.css_framework" instead. // Deprecated since 2.0.0 and ignored: the pages take their classes from nowo_ui_kit. // Default: null
 *         index?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/index.html.twig"
 *         board?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/board.html.twig"
 *         list?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/list.html.twig"
 *         gantt?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/gantt.html.twig"
 *         calendar?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/calendar.html.twig"
 *         tasks?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/tasks.html.twig"
 *         task?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/task.html.twig"
 *         import?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/import.html.twig"
 *         task_move?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/task_move.html.twig"
 *         task_duplicate?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/task_duplicate.html.twig"
 *         task_recurrence?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/task_recurrence.html.twig"
 *         my_questions?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/my_questions.html.twig"
 *         connection?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/connection.html.twig"
 *         connection_containers?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/_connection_containers.html.twig"
 *         connection_badge?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/_connection_badge.html.twig"
 *         connection_progress?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/_connection_progress.html.twig"
 *         connection_history?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/_connection_history.html.twig"
 *         connection_people?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/connection_people.html.twig"
 *         connection_people_summary?: scalar|Param|null, // Default: "@NowoTaskBoardBundle/manage/_connection_people_summary.html.twig"
 *         page_header?: bool|Param, // Since 2.0.0: false leaves the page header out of the board, list, Gantt and import pages, for a host that draws its own. // Default: true
 *         task_time_before?: scalar|Param|null, // Since 2.0.0: a template included before the Time section of the task page and panel, with the page variables (task), e.g. a timer. null = nothing. // Default: null
 *         task_panel?: scalar|Param|null, // Task editing panel without layout (since 1.11), served by the task_panel route for side drawers. // Default: "@NowoTaskBoardBundle/manage/task_panel.html.twig"
 *         filters_collapse?: scalar|Param|null, // Since 1.17: a host partial that folds the list view filters (e.g. UiKit partials/_filters_collapse.html.twig or the host wrapper of it), included as the first child of the filter form with key, fold_key, form and filter_form. Null keeps the bundle "Filters" toggle. Twig global nowo_task_board_filters_collapse. // Default: null
 *         filters_form_class?: scalar|Param|null, // Since 1.17: extra classes on the list view filter form (the host filter card, e.g. "issue-filters panel"). Twig global nowo_task_board_filters_form_class. // Default: ""
 *     },
 *     database?: array{
 *         entity_manager?: scalar|Param|null, // Default: "default"
 *         mapping_type?: "attribute"|"xml"|Param, // Since 2.15.0 (REQ-ARCH-001 rule 2): how the TaskBoard entities are mapped. attribute (default) = the ORM attributes, as before; xml = the Doctrine XML files of Resources/config/doctrine-xml (same tables, columns, indexes and constraints), for a host that keeps its mapping out of PHP attributes. // Default: "attribute"
 *         user_reference?: "entity"|"id"|Param, // Since 2.15.0 (REQ-ARCH-001 rule 2): how the TaskBoard rows reference host users. entity (default) = a Doctrine relation to user_class, as before; id = the user's id in a string column (no relation: user_class need not be a Doctrine entity), found again through user_directory. // Default: "entity"
 *         private_entity_manager?: bool|Param|null, // Since 2.15.0 (REQ-ARCH-001 rule 6): true = the bundle reads and writes its rows through a private entity manager created on the connection, mapping and events of database.entity_manager, so a TaskBoard write never flushes, clears or closes the application's manager. null (default) keeps the 2.14 behaviour, deprecated since 2.28 (a notice when the container is built, and one per application entity class whose pending changes a TaskBoard flush writes); false too, deprecated: true becomes the only mode in 3.0. // Default: null
 *     },
 *     events?: array{ // Since 2.29.0 (REQ-ARCH-001 rule 7, DDD-host checklist B4): when the change events of the bundle's write services are dispatched.
 *         after_host_commit?: bool|Param, // Opt-in (recommended for DDD hosts; planned as the 3.0 default). With database.private_entity_manager: true and doctrine/dbal 4: when the application wrapped a TaskBoard write in its own transaction, the task, member, comment, time, dependency, link and column change events wait for that transaction's commit (and are dropped on its rollback). Without an open transaction they are dispatched at once. false (default): dispatched after the bundle's own write, inside the application's transaction when there is one (2.28 behaviour). Ignored without the private entity manager or with doctrine/dbal 3. // Default: false
 *     },
 *     tenancy?: array{ // Boards per host tenant (since 1.10): every manage route is copied under each `tenant_paths` entry (its {tenantSlug} names the tenant), a tenant sees only its boards and a board or task of another tenant answers 404.
 *         enabled?: bool|Param, // Default: false
 *         tenant_paths?: list<array{ // Default: []
 *             path?: scalar|Param|null, // Replaces the routes' base path (routes.index.path) and holds {tenantSlug}, e.g. /projects/{tenantSlug}/tasks.
 *             name_prefix?: scalar|Param|null, // Prefix of the copied route names, e.g. app_project_ (app_project_nowo_task_board_board).
 *             slug_prefix?: scalar|Param|null, // Slugs this path serves (prefix match); empty: every slug. // Default: ""
 *             slug_requirement?: scalar|Param|null, // Route requirement of {tenantSlug}; default: the slug prefix then [A-Za-z0-9_-]+. // Default: null
 *             open_single_board?: bool|Param, // Since 2.6.0 (TB-18): tenancy.open_single_board for the tenants of this path only (e.g. true for projects, false for personal or organization indexes). Not set: the global tenancy.open_single_board.
 *         }>,
 *         single_board?: bool|Param, // One board per tenant: the tenant index opens its board, created (with board.default_columns) on the first visit. // Default: false
 *         open_single_board?: bool|Param, // Since 2.0.0: a tenant index opens the board when the user sees exactly one. ?boards=1 keeps the list. Since 2.6.0 a tenant_paths entry may set its own open_single_board, which wins over this one. // Default: false
 *         first_board_provisioner?: scalar|Param|null, // Since 2.0.0: service id of a Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisionerInterface, called on a tenant's empty index for a user the create check and the quota event accept. null = the empty index. // Default: null
 *         resolver?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantResolverInterface (slug => tenant key and name; null for a slug the user may not open). null = the slug is the tenant. // Default: null
 *     },
 *     search?: array{ // Since 2.16.0 (spec 234): the text search of the Task API (GET /v2/team/{id}/task?query=…, GET /v2/list/{id}/task?query=…).
 *         provider?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Search\TaskTextSearchInterface. null = DoctrineTaskTextSearch. // Default: null
 *         fulltext?: bool|Param, // MySQL / MariaDB: map and use the FULLTEXT index on the tasks' title and description (and the comments' body with comments). Run the migration first. Other platforms always use LIKE. // Default: false
 *         comments?: bool|Param, // Also match the text of the tasks' comments (not deleted). // Default: false
 *         min_token_length?: int|Param, // The server's innodb_ft_min_token_size: shorter words use LIKE. // Default: 3
 *         max_matches?: int|Param, // More matches than this answer 400 SEARCH_001 (narrow the query); never a silently cut list. // Default: 500
 *     },
 *     search_kit?: array{ // Since 2.25.0: the header search (SearchKit groups "tasks" and "boards", and the Search\Header services). The SearchKit groups are registered only when nowo-tech/search-kit-bundle >= 1.9 is installed; switch a group off or reorder it with nowo_search_kit.groups.<key>.
 *         enabled?: bool|Param, // false = no SearchKit group of this bundle. // Default: true
 *         tasks?: bool|array{ // The "tasks" group (task title and description). false = off.
 *             enabled?: bool|Param, // Default: true
 *             sensitive?: bool|Param, // Titles and description excerpts may hold personal data: SearchKit keeps the group off while its recents keep titles in the browser without revalidation. false = an ordinary group. // Default: true
 *         },
 *         boards?: bool|array{ // The "boards" group (board name). false = off.
 *             enabled?: bool|Param, // Default: true
 *         },
 *         priority?: int|Param, // Tag priority of the groups among the SearchKit providers (the boards group gets one less). // Default: 40
 *         max_boards?: int|Param, // The most visible boards one search keeps (the most recently changed first; a search that stops there is partial and logged as a warning). Bounds the IN-list of the task query. // Default: 200
 *         max_scanned_boards?: int|Param, // The most boards one search reads, in batches of max_boards, to find the visible ones (boards the viewer cannot open count too): bounds the queries and the per-board access checks. Below max_boards it counts as max_boards. // Default: 1000
 *         description_min_length?: int|Param, // Since 2.26.0: the shortest query word matched in task descriptions (shorter words match titles only, so a one- or two-letter query never scans descriptions). With search.fulltext on MySQL / MariaDB, longer words of letters and digits use the FULLTEXT index. // Default: 3
 *         max_candidates?: int|Param, // The most matching rows one search reads and checks (TASK_ACCESS_CHECK): bounds the checks, the visible total and the deepest results page. // Default: 100
 *     },
 *     humans_only_policy?: array{ // Since 2.16.0 (spec 234): options of Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy.
 *         allow_checklist?: bool|Param, // Let the Task API and MCP tick checklist items into a humans-only column (default: refused like any other move). // Default: false
 *     },
 *     column_transition_policy?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Transition\ColumnTransitionPolicyInterface (since 2.16.0, spec 234): whether a task may enter a column, per channel (web, api, mcp, import). null = every move is allowed. Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy refuses the Task API and MCP into columns flagged humans only. // Default: null
 *     member_badge_provider?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Member\MemberBadgeProviderInterface (since 2.18.0, Beacon spec 234): badges next to a member's name (history, comments, assignees), e.g. "Agent" for a service account. Plain-text values with an allow-listed variant, asked once per page. null = no badge. // Default: null
 *     write_provenance_provider?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Provenance\WriteProvenanceProviderInterface (since 2.18.0, Beacon spec 234): the channel (web, app, api, mcp; decided by the host from the path it serves, never from client input) and actor label stored on every task history row, task and comment write. null = the columns stay NULL. // Default: null
 *     max_subtask_depth?: int|Param, // Since 2.20.0 (Beacon spec 234): the most ancestors a task may have (a top-level task has 0, its subtasks 1). A write that would put a task deeper is refused (Task API 409 PARENT_005; the board UI and copies refuse it; imports and provider syncs place the task under its deepest allowed ancestor and warn); a re-parent counts the moved subtree. Trees already deeper stay readable and editable, and may move up. null = no limit (every tree walk stays bounded at 10000 levels). // Default: null
 *     assignee_provider?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface (since 1.10): who may be a member of a task on a board. null = every user of user_class. // Default: null
 *     board?: array{
 *         default_columns?: list<array{ // Default: [{"name":"To do","color":"#94a3b8"},{"name":"In progress","color":"#3b82f6"},{"name":"Done","color":"#22c55e"}]
 *             name?: scalar|Param|null,
 *             color?: scalar|Param|null, // Default: null
 *             icon?: scalar|Param|null, // Icon name such as tabler:bug (since 1.11), or since 2.9.0 one emoji such as 📝 (printed as text, aria-hidden; the name stays the accessible text). // Default: null
 *         }>,
 *         column_icons?: list<scalar|Param|null>,
 *         column_icon_form_type?: scalar|Param|null, // Since 2.9.0: form type of the column icon field in the add / edit column dialogs, e.g. Nowo\UiKitBundle\Form\EmojiPickerType. The bundle's constraint always applies: one emoji, or one of board.column_icons / board.default_columns icons. null = the grid of board.column_icons. // Default: null
 *         column_icon_form_options?: list<mixed>,
 *         column_humans_only_field?: bool|Param|null, // Since 2.16.0 (spec 234): the "Only people can move tasks here" toggle in the add / edit column dialogs. null = shown when column_transition_policy is set. // Default: null
 *         top_level_only?: bool|Param, // The Kanban board shows top-level tasks only (subtasks are counted on their parent card). Since 1.10. // Default: false
 *         max_tasks?: int|Param, // Since 1.21 (REQ-PERF-001): the most tasks the board, list, Gantt and task pages load per board (the first cards of every column, in position order). A board with more shows "Showing N of M tasks". Raise it for very large boards; keep it bounded. // Default: 500
 *         default_view_resolver?: scalar|Param|null, // Since 1.24: service id of a Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface (the view a board opens in for a user: board, list, gantt or, with calendar.enabled, calendar). The plain board route redirects to that route; ?view=board, XHR and links from the same board keep the board view. null = always the board view. // Default: null
 *         toolbar?: bool|Param, // Since 2.0.0: false leaves the strip above the board out. The column-set dialogs stay; a host button opens them with data-nowo-task-board-open. // Default: true
 *         icon?: bool|Param, // Since 1.30.0: true = the New board form asks for an icon, one emoji of at most 16 bytes. An icon a board already has always shows. // Default: false
 *         icon_form_type?: scalar|Param|null, // Since 1.30.0: form type of the icon field, e.g. an emoji picker. The bundle's constraints always apply. null = a text input. // Default: null
 *         icon_form_options?: list<mixed>,
 *         description_format?: "text"|"markdown"|Param, // Since 1.30.0: the format of the description of new boards. markdown goes through the text renderer (nowo_task_board_text), folded after three lines in the board header. Existing boards stay text. // Default: "text"
 *         description_form_type?: scalar|Param|null, // Since 1.30.0: form type of the description field, with no Twig override, e.g. a Markdown editor. null = a textarea. // Default: null
 *         description_form_options?: list<mixed>,
 *         advanced_slug?: bool|Param, // Since 1.30.0: true = the slug is folded under Advanced and generated with transliteration. // Default: false
 *         scope_provider?: scalar|Param|null, // Since 1.30.0: service id of a Nowo\TaskBoardBundle\Board\BoardScopeProviderInterface: the New board form gets a scope step. null = none. // Default: null
 *         tenant_label_provider?: scalar|Param|null, // Since 1.30.0: service id of a Nowo\TaskBoardBundle\Board\BoardTenantLabelProviderInterface: board lists show the tenant label and icon. null = none. // Default: null
 *         create_dialog_header?: scalar|Param|null, // Since 1.24: request header of the host dialogs (e.g. X-Beacon-Dialog). An invalid New board submission carrying it answers 422 with only the create form (manage/_board_create_form.html.twig) for the dialog; without it, 422 with the index page and the New board dialog open. null = always the index page. // Default: null
 *     },
 *     task_panel?: array{
 *         toolbar?: bool|Param, // Since 2.0.0: false leaves the trail and actions out of the task panel, for a host drawer with its own. // Default: true
 *     },
 *     import?: array{ // Task import limits (since 1.22) and the help link of the import forms (since 2.0.0).
 *         help_link?: array{ // Since 2.0.0: the default of the import forms' help link (the include parameter help_link and a Twig global nowo_task_board_import_help_link still win). Needs url, route or url_resolver, and label. Without them: no link.
 *             url?: scalar|Param|null, // An http(s) URL or a path on this site. // Default: null
 *             route?: scalar|Param|null, // A route name; wins over url. // Default: null
 *             url_resolver?: scalar|Param|null, // Since 2.5.0 (TB-17): service id of an ImportHelpLinkUrlResolverInterface that gets the url / route URL (or null) and returns the URL to link, or null for no link. With it, url and route are optional. // Default: null
 *             route_params?: list<mixed>,
 *             label?: scalar|Param|null, // Translation key or text. // Default: null
 *             translation_domain?: scalar|Param|null, // Default: "messages"
 *             anchor_from?: null|"source"|Param, // source = the link's #anchor follows the selected import source. // Default: null
 *             access_attribute?: scalar|Param|null, // Checked with is_granted() before rendering: without it the user sees no link. // Default: null
 *             external?: "auto"|true|false|Param, // true adds target="_blank" rel="noopener noreferrer"; auto does it for a URL on another host. // Default: "auto"
 *         },
 *         max_rows?: int|Param, // The most rows (tasks) one import file may hold; a larger file is refused. // Default: 5000
 *         max_cell_length?: int|Param, // The longest CSV cell, in bytes; a file with a longer cell is refused. // Default: 65536
 *         max_columns?: int|Param, // Since 1.22.1: the most columns of a CSV header or row; a wider file is refused before its rows are read. // Default: 512
 *         max_cells?: int|Param, // Since 1.22.1: the most cells of a CSV file (columns × rows, empty cells included); a larger file is refused before it is parsed. // Default: 1000000
 *         max_json_nodes?: int|Param, // Since 1.22.1: the weight of a JSON import (about 16 bytes of peak decoded memory per unit: strings by their length, list and object tables at their doubled size; 4 000 000 takes a ClickUp API export of about 2 700 tasks within a 128 MB memory_limit, 7 500 000 with 256 MB takes 5 000); a heavier file is refused before it is decoded. // Default: 4000000
 *     },
 *     comments?: array{ // Task comments and @mentions (since 1.16).
 *         cause_labeler?: scalar|Param|null, // Since 2.10.0 (spec 216): service id of a Nowo\TaskBoardBundle\Comment\TaskCommentCauseLabelerInterface: how a comment shows why it was written (TaskChangeContext, e.g. an automation rule). null = the cause is not shown. // Default: null
 *         readers_can_comment?: bool|Param, // People who may read a task but not edit it (read-only boards) may still comment (comment-only access). false = only editors comment. // Default: true
 *         mention_resolver?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface: who may be @mentioned (the people who can read the task). null = the board's assignable users, else its creator and task members. // Default: null
 *     },
 *     gantt?: array{ // Gantt view (since 1.15).
 *         week_start?: "monday"|"sunday"|Param, // First day of the week: the Weeks scale columns and the week separators of the Days scale. // Default: "monday"
 *         week_start_resolver?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Gantt\WeekStartResolverInterface answering the week start per request (e.g. a user preference). null = week_start. // Default: null
 *     },
 *     task_numbers?: array{ // Since 2.1.0 (spec 214): a readable number per task (#123), its ledger, the numbers on the pages and the task_by_number route. Opt-in: false = no routes, services, mapping or output change. Task::$number is mapped either way: see docs/UPGRADING.md.
 *         enabled?: bool|Param, // Default: false
 *         scope?: "tenant"|"board"|Param, // tenant: one sequence per tenant, shared by its boards (a board without a tenant key uses its own id). board: one per board. // Default: "tenant"
 *     },
 *     calendar?: array{ // Since 2.1.0 (spec 214): the Calendar view of a board and the task_schedule route. Opt-in: false = no routes, no tab.
 *         enabled?: bool|Param, // Default: false
 *         day_annotation_provider?: scalar|Param|null, // Since 2.14.0 (Beacon spec 204): service id of a Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotationProviderInterface: working days, holidays and leave of the viewer (day shading and label) and of the assignees (a warning on a task whose assignee is off on one of its days). null = the calendar of 2.13, unchanged. // Default: null
 *         skip_non_working_days?: bool|Param, // Since 2.14.0: with day_annotation_provider, a dragged task (body or edge) lands on the next working day of the viewer when dropped on a non-working one. false = drops land where they fall, as before. // Default: false
 *         events?: array{ // Since 2.24.0: timed events of the calendar event sources (services implementing Nowo\TaskBoardBundle\Calendar\CalendarEventSourceInterface, tag nowo_task_board.calendar_event_source; autoconfigured). Without a source nothing changes.
 *             timezone?: scalar|Param|null, // The timezone the calendar shows event times in and moves events by (an IANA name such as Europe/Madrid). null = the PHP default timezone (date.timezone), read per request. // Default: null
 *             max_per_source?: int|Param, // The most events one source draws on a calendar page; the rest are left out and logged. // Default: 500
 *         },
 *     },
 *     multi_board?: array{ // Since 2.13.0 (Beacon D-20261112-01): the tasks of several boards in one list ("All tasks"), with a Board / Project column, filters, pagination, the workload per person and a CSV / XLSX export. Opt-in: false = no routes, services or output change.
 *         enabled?: bool|Param, // Default: false
 *         scope_provider?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeProviderInterface: the boards each viewer may read here (re-checked with canViewBoard()). null = no board for anyone (deny by default). // Default: null
 *         path?: scalar|Param|null, // Path of the list (route nowo_task_board_tasks); the export is <path>/export.{format} (nowo_task_board_tasks_export). May hold route variables (e.g. /orgs/{organisation}/tasks): they reach the provider in MultiBoardViewer::$context. Not copied under tenancy.tenant_paths. // Default: "/tools/task-board/tasks"
 *         per_page?: int|Param, // Default rows per page: 10, 25, 50 or 100 (the page offers the same choices). // Default: 25
 *         max_boards?: int|Param, // The most boards of a scope the list reads (the rest is left out and logged as a warning): bounds the IN-list of the queries and the per-board access checks. // Default: 500
 *         scan_max_rows?: int|Param, // With a label filter the tasks are compared in PHP (tags are JSON): the most tasks one page, workload or export reads for it (an export reads them twice: once to count). Above it the page says the result is partial and the export sends X-Task-Board-Export-Partial. // Default: 10000
 *         export?: array{
 *             max_rows?: int|Param, // The most rows of one CSV / XLSX export (streamed in batches of 500). Above it the export stops there and carries the header X-Task-Board-Export-Truncated. // Default: 5000
 *         },
 *     },
 *     history?: array{ // Since 2.3.0 (spec 216).
 *         cause_labeler?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\History\TaskChangeCauseLabelerInterface: how the history shows why a change happened (TaskChangeContext). null = the cause is not shown. // Default: null
 *     },
 *     recurrence?: array{ // Since 2.3.0 (spec 215): recurring tasks — the series and occurrence tables, TaskRecurrenceManager, the Repeat section of the task panel, the task_recurrence routes and the repeat icon. No scheduler: the host creates the occurrences (dueSeries(), createOccurrence()). Opt-in: false = no routes, services, mapping or output change.
 *         enabled?: bool|Param, // Default: false
 *         max_subtasks?: int|Param, // The most subtasks an occurrence copies; a series over it is refused with the count. // Default: 200
 *     },
 *     task_templates?: array{ // Since 2.16.0 (Beacon spec 234, FR-061): task templates per board or per tenant — the template table, TaskTemplateManager, the Template picker of the add-task dialog, GET {api.prefix}/v2/list/{id}/taskTemplate and template_id on a new task. Opt-in: false = no table mapping, service, route or output change.
 *         enabled?: bool|Param, // Default: false
 *         permission?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Template\TaskTemplatePermissionInterface: who manages and who uses templates. null = DefaultTaskTemplatePermission (manage a board's templates with the right to manage the board, a tenant's with security.manage_roles; use them with the right to add tasks). // Default: null
 *     },
 *     task_questions?: array{ // Since 2.23.0 (Beacon spec 242): questions on tasks for the business to answer — options (one recommended), single or multiple choice, an Other text, recipients from the host, a due date; answers superseded and kept; withdraw with a reason. The question tables, TaskQuestionManager, the Questions section of the task page, the Waiting for answer badge and filter, My open questions, the task menu entry and the API routes. Opt-in: false = no table mapping, service, route or output change.
 *         enabled?: bool|Param, // Default: false
 *         recipient_resolver?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Question\TaskQuestionRecipientResolverInterface: who may be asked and whom a user answers for. null = DefaultTaskQuestionRecipientResolver (the task's mention candidates as user:<id>). // Default: null
 *         external_answers?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Question\TaskQuestionExternalAnswerInterface: the host-signed link where an external recipient answers (shown to the asker and the board managers, never stored). null = none. // Default: null
 *         decision_publisher?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Question\TaskQuestionDecisionPublisherInterface: the Publish decision action of answered questions (the host decides the target, e.g. a wiki page). null = no action. // Default: null
 *         max_recipients?: int|Param, // The most recipients of one question. // Default: 20
 *         max_open_per_task?: int|Param, // The most open questions of one task. // Default: 20
 *         my_questions_per_page?: int|Param, // Default: 25
 *     },
 *     move?: array{ // Since 2.3.0 (spec 217): move a task and its subtasks to another board, and duplicate a task (routes task_move_board and task_duplicate, actions in the task panel). Opt-in: false = no routes and no actions. TaskCopier, TaskMovePlanner and TaskMover are services either way.
 *         enabled?: bool|Param, // Default: false
 *         max_subtasks?: int|Param, // The most subtasks a move or a duplicate carries; above it the action is refused with the count. // Default: 200
 *         targets?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface: the boards offered as a target (any other answers 404). null = the active boards of the task's tenant the user may edit. // Default: null
 *     },
 *     restructure?: array{ // Since 2.19.0 (Beacon spec 234): folders of boards and moves of tasks between the boards of one tenant (TaskBoardRestructureInterface, the Task API folder routes). Always on: a host without folders sees nothing new.
 *         policy?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Folder\TaskBoardRestructurePolicyInterface: who may manage folders and restructure a board. null = ManageRightRestructurePolicy (the create-board decision for folders, canManageBoard() for a board). // Default: null
 *     },
 *     sync?: array{ // Since 1.23: provider connections (ClickUp first). Opt-in: false = no routes, services, mapping or header change.
 *         enabled?: bool|Param, // Default: false
 *         credential_resolver?: scalar|Param|null, // Service id of a Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface. Required when enabled: the host keeps the tokens. // Default: null
 *         attachment_storage?: scalar|Param|null, // Service id of a TaskAttachmentStorageInterface. null = files are counted as refused (storage_disabled). // Default: null
 *         inline_image_rewriter?: scalar|Param|null, // Service id of an InlineImageRewriterInterface. null = MapInlineImageRewriter. // Default: null
 *         limit_checker?: scalar|Param|null, // Service id of a SyncLimitCheckerInterface (host quotas). null = no host limit. // Default: null
 *         runner?: scalar|Param|null, // Service id of a SyncRunnerInterface (e.g. Messenger). null = InlineSyncRunner (one slice per request, the progress page advances the run). // Default: null
 *         actor_resolver?: scalar|Param|null, // Service id of a SyncActorResolverInterface. null = the user of the request, else the user who linked the board; a disabled user (isEnabled() false) is refused since 1.24. // Default: null
 *         actor_checker?: scalar|Param|null, // Since 1.24: service id of a Symfony UserCheckerInterface (e.g. security.user_checker.main) run with checkPreAuth() on the actor of the default actor_resolver; an exception refuses the run. null = only isEnabled(). // Default: null
 *         providers?: array{
 *             clickup?: array{
 *                 enabled?: bool|Param, // Default: true
 *                 attachment_hosts?: list<scalar|Param|null>,
 *             },
 *         },
 *         http?: array{
 *             client?: scalar|Param|null, // Service id of an untraced HttpClientInterface (e.g. through a proxy). null = the bundle's own client (never the profiler-traced http_client). // Default: null
 *             timeout?: float|Param, // Default: 10.0
 *             max_duration?: float|Param, // Default: 30.0
 *             max_response_bytes?: int|Param, // Default: 8388608
 *             max_inline_wait?: int|Param, // Seconds a rate-limited call waits inline; a longer wait pauses the run (RateLimited, resumable). // Default: 30
 *         },
 *         attachments?: array{
 *             enabled?: bool|Param, // Default: true
 *             max_bytes?: int|Param, // Default: 26214400
 *             max_bytes_per_run?: int|Param, // Default: 1073741824
 *             max_redirects?: int|Param, // Default: 3
 *             allowed_mime_types?: list<scalar|Param|null>,
 *         },
 *         max_tasks_per_run?: int|Param, // Default: 5000
 *         max_pages_per_run?: int|Param, // Since 1.23 (audit EG-L2): the most task pages one run reads over all its slices; past it the run fails with response_too_large. // Default: 1000
 *         max_comments_per_task?: int|Param, // Default: 500
 *         max_description_length?: int|Param, // Default: 100000
 *         max_archive_ratio?: float|Param, // Default: 0.5
 *         history_keep?: int|Param, // Default: 50
 *         container_cache_ttl?: int|Param, // Default: 300
 *         inline_slice_pages?: int|Param, // Task pages the inline runner writes per request. // Default: 5
 *         progress?: array{
 *             poll_interval_ms?: int|Param, // Default: 2000
 *         },
 *         badge?: array{
 *             connect_link?: bool|Param, // Since 1.25.2: false = the connection badge shows nothing for an unlinked board (no "Connect provider" link, no empty strip); a linked board keeps its badge. // Default: true
 *             style?: "badge"|"strip"|Param, // Since 2.5.0 (TB-16): badge = the inline badge (as before); strip = one UiKit notice strip (provider · list and the status on the left, a progress bar while a run is going, Refresh and Connection on the right). Applies to the bundle's own templates.connection_badge partial. // Default: "badge"
 *             progress?: bool|Param, // Since 1.26.3: true = the bundle's own connection badge shows a determinate progress bar of the running run (polling board_connection_progress, managers only). The badge data (activeRunId, progressUrl, total, processed, pollInterval) is given either way, for a host template. // Default: false
 *         },
 *         import_archived?: bool|Param, // Since 1.27.0 (TB-SYNC-3): archived provider tasks are read (ClickUp: a second pass with archived=true) and imported archived. false = 1.26 (an archived task is not created). // Default: true
 *         status_history?: array{
 *             max_calls_per_run?: int|Param, // Since 1.27.0 (TB-SYNC-3): "time in status" requests a run may spend on the tasks it creates or changes (0 = never). // Default: 200
 *         },
 *         comments?: array{
 *             max_reply_calls_per_run?: int|Param, // Since 1.27.0: requests for comment threads per run (0 = no replies). The threads past it are reported, and read when the comment or task next changes. // Default: 500
 *         },
 *         custom_fields?: array{
 *             mode?: "typed"|"text"|"both"|Param, // Since 1.27.0 (TB-SYNC-3): typed = board-level field definitions and typed values per task (task panel, list search); text = the 1.23 "Fields (<provider>)" task document only; both. // Default: "typed"
 *             exclude_types?: list<"text"|"number"|"money"|"date"|"dropdown"|"labels"|"checkbox"|"url"|"email"|"phone"|"rating"|"progress"|"people"|"relationship"|Param>,
 *         },
 *         list_as_tag?: bool|Param, // Since 1.25: tag "list:<name>" on tasks synced from a folder or space source. // Default: false
 *         lookup?: array{
 *             cache_pool?: scalar|Param|null, // Since 1.25: service id of a PSR-6 pool caching the list search (300 s, keyed by a hash of the connection reference, never the token). null = no cache. // Default: null
 *         },
 *         people_bulk_former?: bool|Param, // Since 1.28.0: true = the People page offers "Mark every unmapped person as a former person" (mapping external, changeable person by person). false = no button, and the route answers 404. // Default: false
 *         include_multi_list_tasks?: bool|Param, // Since 1.28.0: true = a ClickUp list source also reads the tasks added to it from other lists (include_timl). Each still appears once, with all its lists; a task belongs to one board. // Default: false
 *         full_sync_rereads_details?: bool|Param, // Since 1.28.0: true = a full sync reads each task's detail again even when its date did not move (within sync.watchers.max_detail_calls_per_run). For when a new file does not move ClickUp's date_updated. // Default: false
 *         unmatched_columns_choice?: bool|Param, // Since 1.28.0: true = the link page offers "Columns no status uses" (keep, or delete the empty ones after the first import). false = not drawn; a posted task_board_sync_link[unmatchedColumns] is accepted either way (a host drawing its own dialog). // Default: false
 *         people?: array{
 *             resolver?: scalar|Param|null, // Since 1.25: service id of a Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface (tenant scope, candidates, verified e-mail match). null = DefaultSyncPersonResolver (1.23 e-mail semantics, one directory per board). // Default: null
 *             require_verified_email?: bool|Param, // Since 1.25: an e-mail match is applied automatically only for a verified account; otherwise it is only suggested. false = 1.23 parity for users without isVerified(). // Default: true
 *             reassign_batch_size?: int|Param, // Default: 500
 *             step?: bool|Param, // Since 1.27.0: true = linking a board does not start the first import: the people are scanned and the manager lands on the People page ("Save and start the import"). false = the import starts at once (1.26); the People page is reachable from the connection page either way. // Default: false
 *             inviter?: scalar|Param|null, // Since 1.27.0: service id of a Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface ("Invite by e-mail" on the People page). null = no invite option. // Default: null
 *             page_size?: int|Param, // Since 1.27.0: people per page of the People page. // Default: 50
 *             candidate_limit?: int|Param, // Since 1.27.0: the most host users offered in each People select (SyncPersonResolverInterface::candidates()). // Default: 200
 *             reapply_seconds?: float|Param, // Since 1.27.0: seconds a People save (and the end of each sync) spends re-applying changed mappings to already imported tasks; the rest continues on the next save or sync. // Default: 10.0
 *             scan?: array{
 *                 max_task_pages?: int|Param, // Default: 1
 *                 max_comment_tasks?: int|Param, // Default: 10
 *                 time_window?: bool|Param, // One 30-day time window in the pre-scan when the token is a workspace admin. // Default: true
 *             },
 *         },
 *         watchers?: array{
 *             enabled?: bool|Param, // Default: true
 *             max_detail_calls_per_run?: int|Param, // Since 1.25: task detail requests (E5) a run may spend on rows that leave out watchers or checklists. // Default: 500
 *         },
 *         checklists?: array{
 *             enabled?: bool|Param, // Default: true
 *             max_items_per_run?: int|Param, // Since 1.25 (audit FP-L1): checklist subtasks a run may create; also counted against sync.limit_checker. // Default: 2000
 *         },
 *         time_entries?: array{
 *             sink?: scalar|Param|null, // Since 1.25: service id of a Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntrySinkInterface. null = the time_entries phase never runs (the bundle never writes time itself). // Default: null
 *             window_days?: int|Param, // Default: 30
 *             max_assignees_per_call?: int|Param, // Default: 25
 *             max_entries_per_window?: int|Param, // Above it the window is halved and read again (down to one hour). // Default: 10000
 *             max_windows_per_run?: int|Param, // Default: 200
 *             fallback_max_tasks_per_run?: int|Param, // Default: 500
 *             max_calls_per_run?: int|Param, // Since 1.25 (audit FP-L4): time-entry requests one slice may spend, splits included; the run then pauses and resumes from the last delivered split. // Default: 500
 *         },
 *     },
 * }
 * @psalm-type NowoUiKitConfig = array{
 *     css_framework?: "bootstrap"|"bootstrap5"|"bootstrap4"|"tailwind"|"foundation"|"custom"|"tabler"|"none"|Param, // Host CSS stack: bootstrap5|bootstrap4|tailwind|foundation|custom|none|tabler (bootstrap alias → bootstrap5). // Default: "bootstrap5"
 *     icon_set?: "bootstrap-icons"|"tabler-icons"|"ux_icon"|"svg_inline"|"none"|Param, // Icon rendering: bootstrap-icons|tabler-icons|ux_icon|svg_inline|none. // Default: "bootstrap-icons"
 *     row_actions_display?: "icon"|"text"|"icon_text"|Param, // Table/list row actions: icon (glyph only) | text (label only) | icon_text (both). // Default: "icon"
 *     row_actions_confirm?: "inline"|"native"|"dialog"|Param, // confirm_message of 1.x row_actions POST hashes (2.22, REQ-UI-003): inline = onsubmit="return confirm(…)" (default, unchanged markup); native | dialog = no inline handler, the confirm-submit Stimulus peer asks (window.confirm or the kit <dialog>). native / dialog need the confirm-submit peer registered. // Default: "inline"
 *     table_confirm?: "per_row"|"shared"|Param, // Row actions with a Confirm in the `table` partial (2.68): per_row = one confirm <dialog> (title, message, POST form + CSRF) per row (default, deprecated: the default becomes shared in 3.0); shared = ONE dialog per table filled when it opens, each row keeps only its toggle and a hidden POST form. The `table` partial option confirm_shared wins. // Default: "per_row"
 *     action_icons?: array<string, scalar|Param|null>,
 *     action_variants?: array<string, scalar|Param|null>,
 *     panel_path_rewrites?: array<string, scalar|Param|null>,
 *     release_check?: array{ // Optional GitHub release-version checker (footer dialog + JSON endpoint). Off by default.
 *         enabled?: bool|Param, // Register the checker service + controller. Also import @NowoUiKitBundle/Resources/config/routes/release_check.yaml. Since 2.33 also a container parameter or "%env(bool:…)%" (false at runtime = the check is skipped and the endpoint answers 404). // Default: false
 *         github_repo?: scalar|Param|null, // Public GitHub repository "owner/name" whose releases are compared (e.g. nowo-tech/my-app). // Default: ""
 *         current_version?: scalar|Param|null, // Installed version label, clean semver x.y.z with optional "v" (e.g. "%app.version%"). Non-semver values skip the check. // Default: ""
 *         cache_ttl?: int|Param, // Seconds to cache the GitHub answer (cache.app). Default 12h. // Default: 43200
 *         default_branch?: scalar|Param|null, // Branch used as compare target when the installed version is already the latest. // Default: "main"
 *         user_agent?: scalar|Param|null, // User-Agent sent to api.github.com. // Default: "nowo-ui-kit-release-check"
 *         timeout?: float|Param, // Idle timeout in seconds of the GitHub "releases/latest" request (HttpClient "timeout"). Keep it below PHP max_execution_time (REQ-RUNTIME-001). // Default: 5.0
 *         releases_timeout?: float|Param, // Idle timeout in seconds of the GitHub "releases" listing used to count versions behind. Keep it below PHP max_execution_time (REQ-RUNTIME-001). // Default: 8.0
 *     },
 *     route_links?: array{ // Route (or route prefix) → link map read by nowo_ui_route_link(), e.g. for the page-edge "manual" tab (partials/_edge_tab.html.twig). A prefix matches the route itself or any route starting with "<prefix>_"; the longest prefix wins.
 *         base_url?: scalar|Param|null, // Prepended to every relative path (paths starting with "/", "//", "http://" or "https://" are used as is). // Default: ""
 *         default_testid?: scalar|Param|null, // testid returned for entries without their own. // Default: null
 *         routes?: array<string, Param|string|array{ // Default: []
 *             path?: scalar|Param|null,
 *             testid?: scalar|Param|null, // Default: null
 *         }>,
 *     },
 *     base_stylesheet?: bool|Param, // 2.51: link UiKit's opt-in default theme (css/nowo-ui-base.css) before nowo-ui.css in the document / error layouts: body font stack, page gutters and masthead height for hosts that bring no CSS framework and no theme stylesheet. Its rules nest in `@layer theme.nowo-ui-base`, below `base`, so a framework, host utilities and unlayered host CSS still win. Leave it false when the host already has a framework or a theme file. // Default: false
 *     font_preload?: array{ // 2.21: <link rel="preload" as="font" crossorigin> tags printed in the <head> of the document and error layouts (Twig nowo_ui_font_preload()) for the fonts painted above the fold. Empty = no tags.
 *         fonts?: list<scalar|Param|null>,
 *         manifest?: array{ // Hashed builds: pick the fonts from a Vite manifest (.vite/manifest.json). A missing manifest (dev server) prints nothing.
 *             path?: scalar|Param|null, // Absolute path of the manifest, e.g. "%kernel.project_dir%/public/build/.vite/manifest.json"; null = off. // Default: null
 *             entry?: scalar|Param|null, // Manifest entry whose "assets" list holds the fonts. // Default: "assets/app.js"
 *             prefixes?: list<scalar|Param|null>,
 *             base?: scalar|Param|null, // Public URL prefix of the manifest assets. // Default: "/build/"
 *         },
 *     },
 *     appearance?: array{ // Optional site appearance SPI (presets, --nowo-ui-* token emitter, Twig functions). Off by default.
 *         enabled?: bool|Param, // Default: false
 *         provider?: scalar|Param|null, // Service id implementing AppearanceProviderInterface (host storage). Null = the config defaults below (or, 2.40, the brand provider: see brand). // Default: null
 *         brand?: "auto"|"off"|Param, // 2.40 (spec 220): auto = with provider null and a nowo-tech/brand-contracts BrandProviderInterface service, the brand drives the name, eyebrow, colours and logos (corner, border and footer stay from defaults). off = always the defaults. No brand service = no change. // Default: "auto"
 *         head_links?: bool|Param, // 2.40: the kit layouts (document, error) print nowo_ui_appearance_head() in <head>: favicon / apple-touch links of the appearance logos and meta theme-color (paper colour per colour scheme). Off = the 2.39 <head>. // Default: false
 *         defaults?: array{
 *             brand_name?: scalar|Param|null, // Default: ""
 *             brand_eyebrow?: scalar|Param|null, // Default: ""
 *             preset?: scalar|Param|null, // Preset whose light colours are the default. // Default: "slate"
 *             preset_dark?: scalar|Param|null, // Preset whose dark colours are the default. // Default: "midnight"
 *             corner_style?: "sharp"|"soft"|"rounded"|Param, // Default: "soft"
 *             border_strength?: "subtle"|"medium"|"strong"|Param, // Default: "medium"
 *             footer_fixed?: bool|Param, // Default: false
 *         },
 *         presets?: array<string, array{ // Default: []
 *             mode?: "light"|"dark"|Param, // Default: "light"
 *             light?: array{
 *                 accent?: scalar|Param|null,
 *                 accent_deep?: scalar|Param|null,
 *                 danger?: scalar|Param|null,
 *                 warn?: scalar|Param|null,
 *                 paper?: scalar|Param|null,
 *                 ink?: scalar|Param|null,
 *                 surface?: scalar|Param|null,
 *             },
 *             dark?: array{
 *                 accent?: scalar|Param|null,
 *                 accent_deep?: scalar|Param|null,
 *                 danger?: scalar|Param|null,
 *                 warn?: scalar|Param|null,
 *                 paper?: scalar|Param|null,
 *                 ink?: scalar|Param|null,
 *                 surface?: scalar|Param|null,
 *             },
 *         }>,
 *         builtin_presets?: list<scalar|Param|null>,
 *         kit_tokens?: bool|Param, // Emit the --nowo-ui-* tokens. False = only the token_aliases are written. // Default: true
 *         token_aliases?: array<string, list<scalar|Param|null>>,
 *         selectors?: array{ // Selectors of the three emitted blocks.
 *             root?: scalar|Param|null, // Default: ":root"
 *             light?: scalar|Param|null, // Default: ":root, [data-theme=\"light\"]"
 *             dark?: scalar|Param|null, // Default: "[data-theme=\"dark\"]"
 *         },
 *         shadow_rgb_fallback?: scalar|Param|null, // --nowo-ui-shadow-rgb when the light ink colour is not hex ("r g b"). // Default: "15 23 42"
 *     },
 *     layouts?: array{ // Layouts the page scaffolds extend (Twig global nowo_ui_layout). Host layouts or one-file bridges must render the canonical nowo_ui_* blocks.
 *         admin?: scalar|Param|null, // Default: "@NowoUiKit/layout/admin.html.twig"
 *         guest?: scalar|Param|null, // Default: "@NowoUiKit/layout/guest.html.twig"
 *     },
 *     breadcrumbs?: array{ // Page header breadcrumbs: BreadcrumbKit (breadcrumb_render) when installed and enabled here, else the plain trail from PageHeader::breadcrumbs.
 *         breadcrumb_kit?: bool|Param, // Use BreadcrumbKit when its breadcrumb_render() Twig function exists. // Default: true
 *         collection?: scalar|Param|null, // BreadcrumbKit collection code (null = its default collection). PageHeader::breadcrumbCollection overrides it. // Default: null
 *         enabled?: bool|Param, // 2.3.1: false drops the breadcrumb trail (BreadcrumbKit and PageHeader::breadcrumbs) from every page header, e.g. when the host layout renders its own. // Default: true
 *     },
 *     header?: array{ // 2.27 compact header: the default page-header presentation (a request attribute _nowo_ui_header_presentation overrides it per request) and where PageHeader::actions go in the compact presentation.
 *         presentation?: "standard"|"compact"|"inline"|"no_intro"|"no_title"|"no_header"|Param, // standard (default) | compact | inline | no_intro | no_title | no_header: in compact the page header keeps a visually hidden h1 and leaves its intro, breadcrumbs and back link to the top bar (header_context); 2.39: inline puts the intro on the title row, no_intro drops the intro, no_title keeps the h1 visually hidden, no_header does both. // Default: "standard"
 *         compact_actions?: "page"|"bar"|Param, // page (default): header.actions stay in the page header; bar: in the compact presentation the page header leaves them out (the host shows them in the top bar through the page_actions partial). extra_actions always stay. // Default: "page"
 *     },
 *     auth_panel?: array{ // 2.44: the auth scaffold (page/auth.html.twig) panel (Twig global nowo_ui_kit_auth_panel).
 *         brand?: "auto"|"none"|Param, // auto (default, the 2.43 markup): the panel brand region prints the appearance logo lockup, else AuthPage::brand as text; none: the panel prints no brand region (a host guest layout that prints its own brand). A render variable nowo_ui_auth_brand (auto | none) wins over it. // Default: "auto"
 *     },
 *     dialogs?: array{ // 2.70: the runtime that drives the on-demand dialogs (the remote_form_dialog shell, confirm_dialog with dialog_src, the shared confirm dialog of confirm_shared / table_confirm: shared).
 *         runtime?: "iife"|"stimulus"|Param, // iife (default, unchanged markup): js/nowo-ui-dialog.js and js/nowo-ui-confirm.js drive them. stimulus: the shells also carry the Stimulus peers (remote-form-dialog in delegated mode, confirm-shared), for pages that load only the peers; the openers keep their markup, and with both runtimes loaded the peers win (the IIFE runtimes leave a dialog claimed by a peer alone). The partial option `runtime` (or `stimulus: true` on confirm_dialog) wins. // Default: "iife"
 *     },
 *     remote_dialog?: array{ // 2.28 remote form dialog (X-Nowo-Dialog requests).
 *         convert_redirects?: bool|Param, // Turn a RedirectResponse answered to a dialog request (X-Nowo-Dialog: 1, main request) into 204 + X-Nowo-Redirect when its Location is a same-origin path, so redirectToRoute() controllers work in the dialog without code changes. // Default: false
 *         step_routes?: list<scalar|Param|null>,
 *         request_header?: scalar|Param|null, // 2.33: request header the dialog sends with "1" (both drivers, RemoteDialogResponder, the redirect listener, nowo_ui_dialog_request()). // Default: "X-Nowo-Dialog"
 *         redirect_header?: scalar|Param|null, // 2.33: response header of the success answer (204 + <path>: the dialog closes and the page goes there). // Default: "X-Nowo-Redirect"
 *         location_header?: scalar|Param|null, // 2.33: response header of the step answer (204 + <path>: the dialog stays open and loads the next step). // Default: "X-Nowo-Dialog-Location"
 *     },
 *     theme?: array{ // Global theme (REQ-UI-003): semantic slot classes / attributes, partial overrides and --nowo-ui-* tokens, configured once for every bundle.
 *         framework_defaults?: bool|Param, // false = slots print the semantic nowo-ui-* class and the host classes only (no UiKit framework defaults such as btn / badge / card). Per slot: classes.<slot>: {value: …, framework_defaults: false}. // Default: true
 *         classes?: array<string, mixed>,
 *         attributes?: array<string, array<string, mixed>>,
 *         partials?: array<string, scalar|Param|null>,
 *         tokens?: array<string, mixed>,
 *         tokens_dark?: array<string, mixed>,
 *         confirm_message_in_header?: bool|Param, // Confirm dialogs and the full-page confirm render the message inside the header, under the title (hosts whose dialog header is title + message). View\Confirm::messageInHeader wins when set. // Default: false
 *         form_actions_order?: "cancel_first"|"primary_first"|Param, // 2.66: order of the form actions row (form scaffold, `form_actions` partial). primary_first (default; 2.66.1 restores the 2.65 order) = the primary first, secondary actions, Cancel last; cancel_first (opt-in) = Cancel, secondary actions, then the primary last, on the right. Dialog footers, dialog form fragments (Cancel closes the dialog), the full-page confirm and the wizard always keep Cancel / Back first. The DOM order is the visual and focus order (never reversed with CSS). // Default: "primary_first"
 *         popover_menus?: bool|Param, // 2.4: menu / kebab / row-action menus render their panel as a native [popover] (browser top layer: never clipped by table scrollers or cards; nowo-ui-popover.js places it). The partial option `popover` wins. // Default: false
 *         context_menus?: bool|Param, // 2.54: kebab / row-action menus also open as the context menu of the row or card around them (right-click, the ContextMenu key, Shift+F10, long press on touch), with the same items. Loads the opt-in context-menu runtime through the scripts partial. The partial option `context_menu` wins; data-nowo-ui-context-menu="off" opts a row out. // Default: false
 *         form_errors_after_widget?: bool|Param, // 2.10: the UiKit form theme (form/nowo_ui_layout.html.twig) prints a field's errors after its widget (and before the help), instead of Symfony's order (label, errors, widget, help). // Default: false
 *         menu_semantics?: null|"menu"|"disclosure"|Param, // 2.28: ARIA of popover menus with items. null (default) = as 2.27; menu = APG menu button (role="menu" / menuitem, arrow keys in nowo-ui-popover.js); disclosure = no aria-haspopup. The menu partial option `menu_semantics` wins. // Default: null
 *         dialog?: array{ // 2.31: kit dialog options (docs/THEMING.md#dialog-sizes-229).
 *             height?: "content"|"size"|Param, // 2.31: content (default, dialog rule v2) = a dialog is as tall as its content, capped at min(the size's height token, 100dvh - 2rem), only the body scrolls; size = every dialog with a field, list, table or fold has the size's height (2.29 / 2.30), printed as --nowo-ui-dialog-fill: 1 by nowo_ui_theme_css(). // Default: "content"
 *         },
 *         focus_mode?: array{ // 2.55: shell focus mode — [data-nowo-ui-focus-mode] toggles hide the shell regions (level 1) and optionally enter the browser fullscreen (level 2); js/nowo-ui-focus-mode.js. See docs/USAGE.md#shell-focus-mode-255.
 *             enabled?: bool|Param, // false (default): no shell attributes, no focus bar, the focus_mode_toggle partial renders nothing and the scripts partial does not load the runtime. // Default: false
 *             regions?: list<scalar|Param|null>,
 *             keep?: list<scalar|Param|null>,
 *             shortcut?: scalar|Param|null, // Key that toggles level 1 on pages with a toggle (never while typing, with a modifier pressed or with a dialog / menu open); e.g. F or Alt+Shift+F. null or false: no shortcut. // Default: "F"
 *             fullscreen?: bool|Param, // Offer level 2 (the browser fullscreen on the shell, from the focus bar or a fullscreen toggle). Unsupported or refused by the browser: level 1 stays. // Default: true
 *         },
 *         bundles?: array<string, array{ // Default: []
 *             framework_defaults?: bool|Param, // Override of theme.framework_defaults for this bundle (unset = inherit).
 *             classes?: array<string, mixed>,
 *             attributes?: array<string, array<string, mixed>>,
 *             partials?: array<string, scalar|Param|null>,
 *             tokens?: array<string, mixed>,
 *             tokens_dark?: array<string, mixed>,
 *             pages?: array<string, array{ // Default: []
 *                 framework_defaults?: bool|Param, // Override of theme.framework_defaults for this page type (unset = inherit).
 *                 classes?: array<string, mixed>,
 *                 attributes?: array<string, array<string, mixed>>,
 *             }>,
 *         }>,
 *         pages?: array<string, array{ // Default: []
 *             framework_defaults?: bool|Param, // Override of theme.framework_defaults for this page type (unset = inherit).
 *             classes?: array<string, mixed>,
 *             attributes?: array<string, array<string, mixed>>,
 *             header_actions_in_filters?: bool|Param, // list only: render the page-header actions at the end of the filter row instead of the page header (ListPage::headerActionsInFilters wins when set; unset = false).
 *             filters?: array{ // list only (2.30): host options of the list scaffold filters (page/_list_filters.html.twig). Unset = the 2.29 output.
 *                 display?: null|"text"|"icon"|"icon_text"|Param, // Default display of the apply / clear / extra filter buttons (ListPage::filtersDisplay and Action::display win; set, the plain clear link follows it too). null = the 2.29 behaviour. // Default: null
 *                 submit_icon?: scalar|Param|null, // Icon of the apply button when it shows one (components/_icon.html.twig name; null = filter). ListPage::filtersSubmit->icon wins. // Default: null
 *                 clear_icon?: scalar|Param|null, // Icon of the plain clear link (ListPage::filtersClearHref) when it shows one (null = filter_off). // Default: null
 *                 submit_class?: scalar|Param|null, // Host classes of the apply button, replacing its btn_<variant> slot classes (nowo-ui-filters__submit and, icon-only, the btn_icon slot stay). null = the slot classes. // Default: null
 *                 clear_class?: scalar|Param|null, // Host classes of the clear button (plain link or ListPage::filtersClear Action), replacing its btn_<variant> slot classes. null = the slot classes. // Default: null
 *                 grid?: scalar|Param|null, // Grid tracks of the filter fields: auto-fit (default: a lone filter stretches across the card), auto-fill (empty tracks keep their width) or a grid-template-columns track list such as "repeat(auto-fill, minmax(min(100%, 13rem), 1fr))" (printed as the --nowo-ui-filters-grid-columns token by nowo_ui_tokens(); no inline style). // Default: "auto-fit"
 *                 fold?: array{ // Fold toggle of the filters card (partials/_filters_collapse.html.twig, Stimulus peer filters-collapse). ListPage::filtersFold wins.
 *                     enabled?: bool|Param, // Render the fold toggle (the card renders open unless the cookie remembers it folded). // Default: false
 *                     cookie?: scalar|Param|null, // Cookie remembering the folded pages (comma-separated page keys; SameSite=Lax, no secrets). // Default: "nowo_ui_filters_collapsed"
 *                     cookie_path?: scalar|Param|null, // Path of that cookie. // Default: "/"
 *                     icons?: array{
 *                         expanded?: scalar|Param|null, // Icon while the card is open. // Default: "chevron_up"
 *                         collapsed?: scalar|Param|null, // Icon while the card is folded. // Default: "chevron_down"
 *                     },
 *                     testids?: bool|Param, // data-testid filters-collapse-toggle / filters-collapse-summary. // Default: false
 *                     morph?: bool|Param, // Add the <morph-icon> of assets/morphicons to the toggle. // Default: false
 *                 },
 *                 sheet?: bool|Param, // 2.65: below 48rem the filters card folds behind a "Filters (n)" button that opens it in a native modal <dialog> bottom sheet (the filter_bar sheet: macros/filter_sheet.html.twig, runtime filter-sheet, appended to the scripts partial by itself). false = the 2.64 output. // Default: false
 *             },
 *         }>,
 *     },
 *     error_pages?: array{ // Kit error pages (2.6): templates/bundles/TwigBundle/Exception/error.html.twig = {% extends '@NowoUiKit/error/status.html.twig' %}.
 *         layout_template?: scalar|Param|null, // Document the status template extends: the kit layout, or a host template extending it (own <head> assets, brand, chat dock…). // Default: "@NowoUiKit/error/layout.html.twig"
 *         translation_domain?: scalar|Param|null, // Domain of the page texts (e.g. messages). Keys missing there fall back to UiKit's own NowoUiKitBundle error.* texts. // Default: "NowoUiKitBundle"
 *         translation_prefix?: scalar|Param|null, // Key prefix: <prefix>.<code>.title|lead|hint|image_alt, <prefix>.generic.*, <prefix>.home|retry|back|retry_after|auto_retry|skip_to_content. // Default: "error"
 *         home_path?: scalar|Param|null, // Href of the "Back to home" button. // Default: "/"
 *         illustrations?: array<string, Param|string|array{ // Default: []
 *             path?: scalar|Param|null,
 *             width?: int|Param, // Default: null
 *             height?: int|Param, // Default: null
 *             webp?: bool|Param|null, // Default: null
 *             extension?: scalar|Param|null, // Default: null
 *         }>,
 *         illustration_width?: int|Param, // Default intrinsic width of the illustrations. // Default: 1024
 *         illustration_height?: int|Param, // Default intrinsic height of the illustrations. // Default: 683
 *         illustration_provider?: scalar|Param|null, // 2.47: service id implementing Nowo\UiKitBundle\View\ErrorIllustrationProviderInterface, asked first per status code (null answer, any exception or an invalid illustration → the illustrations map). Must be fast and must not need the database. Null = the map only. // Default: null
 *         illustration_provider_budget_ms?: int|Param, // 2.47: when > 0, a provider call slower than this many milliseconds makes the rest of the same request skip the provider (logged once). 0 = no budget. // Default: 0
 *         webp?: bool|Param, // Illustrations ship a .webp next to the fallback image (<source type="image/webp">). // Default: true
 *         retry_statuses?: list<int|Param>,
 *         back_link_statuses?: list<int|Param>,
 *         auto_retry_seconds?: int|Param, // A 5xx on GET reloads itself after this many seconds (REQ-ERROR-001 item 10; 0 = never). Never on POST. // Default: 600
 *         theme_storage?: scalar|Param|null, // localStorage key of the theme (data-nowo-ui-theme-storage on <html> and the toggle), e.g. the host's beacon-theme. // Default: "nowo-ui-theme"
 *         theme_toggle?: bool|Param, // Render the light / dark toggle in the top controls. // Default: true
 *         html_attributes?: array<string, mixed>,
 *     },
 *     shell?: array{ // Signed-in app shell (2.6, @NowoUiKit/shell/_open|_close|_shell|_burger.html.twig; Twig global nowo_ui_kit_shell).
 *         kit_styles?: bool|Param, // Add the kit shell classes (nowo-ui-shell, nowo-ui-header, nowo-ui-aside…) painted by nowo-ui.css and driven by nowo-ui-shell.js. false: only the app_* slot classes (host shell CSS / JS). // Default: true
 *         sidebar_cookie?: scalar|Param|null, // Cookie holding the desktop aside mode (e.g. beacon_sidebar), read server-side so the first paint is already collapsed / rail; null = expanded unless the template passes collapsed. // Default: null
 *         sidebar_hidden_value?: scalar|Param|null, // Cookie value of the hidden aside. // Default: "collapsed"
 *         sidebar_rail_value?: scalar|Param|null, // Cookie value of the icons-only rail. // Default: "rail"
 *         sidebar_storage?: scalar|Param|null, // localStorage key of the aside mode (data-nowo-ui-sidebar-storage on the shell root; kit_styles + rail only). // Default: null
 *         sidebar_layout?: "below-header"|"full-height"|Param, // 2.32: below-header (default, the 2.31 markup byte for byte) | full-height: on desktop the aside runs from the top edge with the burger and the brand in its head (aside_brand_html / brand_icon_html + brand_name / brand_html), the header and the footer start at its right edge. The app_shell_open option sidebar_layout, then the request attribute _nowo_ui_sidebar_layout, win over it. // Default: "below-header"
 *         sidebar_style?: "classic"|"inset"|Param, // 2.52: classic (default, the 2.51 markup byte for byte) | inset: on desktop the header, the aside and the footer share one shell surface with no border between them, and the content is an inset panel with rounded corners (tokens --nowo-ui-shell-inset-gap / -radius / -surface / -panel-surface). Phones are unchanged. The app_shell_open option sidebar_style, then the request attribute _nowo_ui_sidebar_style, win over it. // Default: "classic"
 *         brand?: array{ // 2.32: the shell brand from config (no template override): the aside head of the full-height layout, and the header brand when a page passes neither brand_html nor brand. The app_shell_open options brand_name / brand_href / brand_icon_src / brand_icon_html / aside_brand_html win.
 *             name?: scalar|Param|null, // Brand name (text); the icons-only rail hides it and keeps it as the link name. // Default: null
 *             href?: scalar|Param|null, // Brand link: relative, http, https or mailto (validated when the container compiles; javascript:, data: and other schemes are refused). // Default: null
 *             icon_src?: scalar|Param|null, // Brand icon image URL (e.g. /images/logo.svg; prefer same-origin or https), rendered as <img> in every aside mode; without it the name's initial. Relative, http or https only (validated when the container compiles). // Default: null
 *         },
 *         hover_expand?: bool|Param, // 2.32: with kit_styles and rail_mode, the icons-only rail widens over the page while the pointer or the keyboard focus is on it (data-nowo-ui-sidebar-hover-expand on the shell root, is-aside-peek set by nowo-ui-shell.js). The app_shell_open option hover_expand wins. // Default: false
 *     },
 * }
 * @psalm-type NowoFormKitConfig = array{
 *     type_map?: array<string, scalar|Param|null>,
 *     default_profile?: scalar|Param|null, // Name of the profile to use when no profile is specified (key in profiles) // Default: "default"
 *     convention_requires_translation?: bool|Param, // Global default for profiles.<name>.convention_requires_translation: only apply auto help / placeholder convention keys that exist in the translator catalogue. Requires a translator implementing TranslatorBagInterface (no effect otherwise). Default: false. // Default: false
 *     css_framework?: scalar|Param|null, // CSS framework for CssClassUtilities (column merge + class ordering): bootstrap, tailwind, foundation, none. // Default: "bootstrap"
 *     stateless_csrf?: array{ // Give selected (usually anonymous/public) form types a stateless CSRF token id so rendering them does not start a session. Off by default.
 *         enabled?: bool|Param, // Default: false
 *         token_id?: scalar|Param|null, // csrf_token_id default applied to the listed form types. Must be listed in framework.csrf_protection.stateless_token_ids. // Default: "submit"
 *         form_types?: list<scalar|Param|null>,
 *         register_stateless_token_id?: bool|Param, // Prepend token_id to framework.csrf_protection.stateless_token_ids (enables framework CSRF protection if it was not configured). // Default: true
 *     },
 *     accessibility?: array{ // Accessibility helpers applied by kit form type extensions.
 *         unlabelled_choice_aria_label?: bool|Param, // When true (default), non-expanded ChoiceType fields (and subtypes such as EntityType) rendered with label: false and no aria-label/aria-labelledby get aria-label from their translated help, else their placeholder. // Default: true
 *     },
 *     locale_tabs?: array{ // Locale tabs (2.13): the locale_tabs option, TranslatableMapType and the _locale_tabs include.
 *         locales?: list<scalar|Param|null>,
 *         default_locale?: scalar|Param|null, // Default language (marked and listed first). Null (default) = kernel.default_locale when it is one of the locales, else the first locale. // Default: null
 *         renderer?: "auto"|"ui_kit"|"native"|Param, // auto (default): the UiKit locale_tabs partial when nowo-tech/ui-kit-bundle 2.5+ is installed, else the neutral FormKit markup; native: always the neutral markup; ui_kit: same as auto (falls back to native without UiKit). // Default: "auto"
 *         form_theme?: bool|Param, // When true (default), registers @NowoFormKitBundle/form/locale_tabs_theme.html.twig in twig.form_themes (it only renders fields with locale_tabs). // Default: true
 *     },
 *     field_widgets?: array{ // Field widgets (2.22): the choice_cards (ChoiceType), switch (CheckboxType) and char_counter (TextType) options.
 *         form_theme?: bool|Param, // When true (default), registers @NowoFormKitBundle/form/field_widgets_theme.html.twig in twig.form_themes (it only renders fields with choice_cards, switch or char_counter). // Default: true
 *     },
 *     twig?: array{ // Twig helpers shipped by the bundle (registered only when twig/twig is installed).
 *         csrf_action_form?: bool|Param, // When true (default), registers the csrf_action_form() Twig function (CSRF-only single-action POST forms). Set false if the host keeps its own function with that name. // Default: true
 *     },
 *     profiles?: array<string, array{ // Default: []
 *         alias?: scalar|Param|null, // Alias for this profile (e.g. for reference in form types)
 *         translation_domain?: scalar|Param|null, // Default: "messages"
 *         auto_placeholder?: bool|Param, // When true (default), unset placeholders become {form}.{field}.placeholder translation keys. Set false for kits that only set explicit labels. // Default: true
 *         auto_help?: bool|Param, // When true (default), unset help becomes {form}.{field}.help translation keys. Set false to avoid raw missing-help keys in the UI. // Default: true
 *         convention_requires_translation?: bool|Param|null, // When true, the auto help / placeholder convention keys are only applied if the key exists in the translator catalogue (current locale + fallbacks) for the field's translation domain. Never affects labels. Null (default) inherits the root convention_requires_translation. // Default: null
 *         required_label_suffix?: scalar|Param|null, // Appended to the label when the field is required (e.g. " *"). Empty or null to disable. // Default: null
 *         help_attr?: array<string, scalar|Param|null>,
 *         css_framework?: scalar|Param|null, // CSS framework of this profile's form theme chrome (2.13; static_blocks checkbox_row): bootstrap adds form-check / form-check-input / form-check-label and the mb-3 row fallback. Null (default) = the root css_framework. // Default: null
 *         help_modal?: array{ // Default help modal configuration (used when the field option "help_modal" is enabled).
 *             framework?: scalar|Param|null, // Modal framework to use when opening from frontend. // Default: "bootstrap5"
 *             icon_html?: scalar|Param|null, // HTML snippet inserted next to the label to trigger the help modal (fallback when ux_icon is not used or UX Icons is unavailable). // Default: "<span class=\"nowo-help-modal-icon\" aria-hidden=\"true\">?</span>"
 *             ux_icon?: scalar|Param|null, // Optional. Symfony UX Icons name (e.g. lucide:circle-help). Requires symfony/ux-icons; when set and IconRendererInterface is available, overrides icon_html. // Default: null
 *             ux_icon_attributes?: array<string, scalar|Param|null>,
 *             trigger_class?: scalar|Param|null, // CSS classes for the clickable trigger wrapper (after label text and required suffix). Default: circle button style. // Default: "nowo-help-modal-trigger nowo-help-modal-trigger--circle"
 *         },
 *         defaults?: array{
 *             attr?: array<string, scalar|Param|null>,
 *             row_attr?: array<string, scalar|Param|null>,
 *             help_attr?: array<string, scalar|Param|null>,
 *             label?: scalar|Param|null, // Default label for every field (e.g. false to suppress). When set, overrides the {form}.{field}.label convention.
 *             placeholder?: scalar|Param|null, // Default placeholder for every field (e.g. false to suppress). When set, overrides auto_placeholder convention.
 *             help?: scalar|Param|null, // Default help for every field (e.g. false to suppress). When set, overrides auto_help convention.
 *             required?: bool|Param, // Default required flag for every field. Overridable via field_types, by_form, or PHP options.
 *         },
 *         field_types?: array<string, array{ // Default: []
 *             attr?: array<string, scalar|Param|null>,
 *             row_attr?: array<string, scalar|Param|null>,
 *             help_attr?: array<string, scalar|Param|null>,
 *             label?: scalar|Param|null,
 *             placeholder?: scalar|Param|null,
 *             help?: scalar|Param|null,
 *             required?: bool|Param, // Default required flag for this field type / field. Overridable by later cascade layers or PHP options.
 *             translation_domain?: scalar|Param|null,
 *             constraints?: list<mixed>,
 *         }>,
 *         constraint_message_convention?: bool|Param, // When true, constraints without an explicit "message" get key {form_snake}.{field_snake}.constraints.{ConstraintName} (put translations in the validators catalog). Default: false. // Default: false
 *         by_form?: array<string, array{ // Default: []
 *             defaults?: array{
 *                 attr?: array<string, scalar|Param|null>,
 *                 row_attr?: array<string, scalar|Param|null>,
 *                 help_attr?: array<string, scalar|Param|null>,
 *                 label?: scalar|Param|null, // Default label for every field (e.g. false to suppress). When set, overrides the {form}.{field}.label convention.
 *                 placeholder?: scalar|Param|null, // Default placeholder for every field (e.g. false to suppress). When set, overrides auto_placeholder convention.
 *                 help?: scalar|Param|null, // Default help for every field (e.g. false to suppress). When set, overrides auto_help convention.
 *                 required?: bool|Param, // Default required flag for every field. Overridable via field_types, by_form, or PHP options.
 *             },
 *             fields?: array<string, array{ // Default: []
 *                 attr?: array<string, scalar|Param|null>,
 *                 row_attr?: array<string, scalar|Param|null>,
 *                 help_attr?: array<string, scalar|Param|null>,
 *                 label?: scalar|Param|null,
 *                 placeholder?: scalar|Param|null,
 *                 help?: scalar|Param|null,
 *                 required?: bool|Param, // Default required flag for this field type / field. Overridable by later cascade layers or PHP options.
 *                 translation_domain?: scalar|Param|null,
 *                 constraints?: list<mixed>,
 *             }>,
 *         }>,
 *     }>,
 *     translation_domain?: scalar|Param|null, // (Legacy) Used when profiles is not set // Default: "messages"
 *     required_label_suffix?: scalar|Param|null, // (Legacy) Suffix for required field labels when profiles is not set // Default: null
 *     help_modal?: array{ // (Legacy) Default help modal configuration when profiles is not used.
 *         framework?: scalar|Param|null, // Default: "bootstrap5"
 *         icon_html?: scalar|Param|null, // Default: "<span class=\"nowo-help-modal-icon\" aria-hidden=\"true\">?</span>"
 *         ux_icon?: scalar|Param|null, // Default: null
 *         ux_icon_attributes?: array<string, scalar|Param|null>,
 *         trigger_class?: scalar|Param|null, // Default: "nowo-help-modal-trigger nowo-help-modal-trigger--circle"
 *     },
 *     defaults?: array{
 *         attr?: array<string, scalar|Param|null>,
 *         row_attr?: array<string, scalar|Param|null>,
 *         help_attr?: array<string, scalar|Param|null>,
 *         label?: scalar|Param|null, // Default label for every field (e.g. false to suppress). When set, overrides the {form}.{field}.label convention.
 *         placeholder?: scalar|Param|null, // Default placeholder for every field (e.g. false to suppress). When set, overrides auto_placeholder convention.
 *         help?: scalar|Param|null, // Default help for every field (e.g. false to suppress). When set, overrides auto_help convention.
 *         required?: bool|Param, // Default required flag for every field. Overridable via field_types, by_form, or PHP options.
 *     },
 *     field_types?: array<string, array{ // Default: []
 *         attr?: array<string, scalar|Param|null>,
 *         row_attr?: array<string, scalar|Param|null>,
 *         help_attr?: array<string, scalar|Param|null>,
 *         label?: scalar|Param|null,
 *         placeholder?: scalar|Param|null,
 *         help?: scalar|Param|null,
 *         required?: bool|Param, // Default required flag for this field type / field. Overridable by later cascade layers or PHP options.
 *         translation_domain?: scalar|Param|null,
 *         constraints?: list<mixed>,
 *     }>,
 *     constraint_message_convention?: bool|Param, // (Legacy) Used when profiles is not set // Default: false
 *     by_form?: array<string, array{ // Default: []
 *         defaults?: array{
 *             attr?: array<string, scalar|Param|null>,
 *             row_attr?: array<string, scalar|Param|null>,
 *             help_attr?: array<string, scalar|Param|null>,
 *             label?: scalar|Param|null, // Default label for every field (e.g. false to suppress). When set, overrides the {form}.{field}.label convention.
 *             placeholder?: scalar|Param|null, // Default placeholder for every field (e.g. false to suppress). When set, overrides auto_placeholder convention.
 *             help?: scalar|Param|null, // Default help for every field (e.g. false to suppress). When set, overrides auto_help convention.
 *             required?: bool|Param, // Default required flag for every field. Overridable via field_types, by_form, or PHP options.
 *         },
 *         fields?: array<string, array{ // Default: []
 *             attr?: array<string, scalar|Param|null>,
 *             row_attr?: array<string, scalar|Param|null>,
 *             help_attr?: array<string, scalar|Param|null>,
 *             label?: scalar|Param|null,
 *             placeholder?: scalar|Param|null,
 *             help?: scalar|Param|null,
 *             required?: bool|Param, // Default required flag for this field type / field. Overridable by later cascade layers or PHP options.
 *             translation_domain?: scalar|Param|null,
 *             constraints?: list<mixed>,
 *         }>,
 *     }>,
 *     ...<string, mixed>
 * }
 * @psalm-type NowoHotReloadConfig = array{
 *     enabled?: bool|Param, // Master switch. When false, nothing is injected even if FRANKENPHP_HOT_RELOAD is set. // Default: true
 *     auto_inject?: bool|Param, // When true, HotReloadResponseSubscriber injects assets into HTML responses. // Default: true
 *     ignore_path_prefixes?: list<scalar|Param|null>,
 *     client_mode?: "cdn"|"visibility"|"shared_worker"|"always"|Param, // Browser Mercure client strategy: cdn (default ESM), visibility (SSE while tab visible), shared_worker (one SSE for all tabs), always (SSE per tab). // Default: "cdn"
 *     require_frankenphp_env?: bool|Param, // When true (default), inject only if FRANKENPHP_HOT_RELOAD is set or mercure_url is configured. // Default: true
 *     allow_production?: bool|Param, // When false (default), enabling this bundle in the prod environment raises InvalidConfigurationException. // Default: false
 *     mercure_url?: scalar|Param|null, // Optional Mercure hub URL. When null, uses $_SERVER['FRANKENPHP_HOT_RELOAD'] when present. // Default: null
 *     idiomorph?: bool|Param, // When true, include Idiomorph for DOM morphing instead of a full page reload. // Default: true
 *     idiomorph_script_url?: scalar|Param|null, // URL of the Idiomorph script (classic script tag). Prefer a version-pinned CDN URL. // Default: "https://cdn.jsdelivr.net/npm/idiomorph@0.7.4"
 *     hot_reload_script_url?: scalar|Param|null, // URL of the frankenphp-hot-reload ESM module. Prefer a version-pinned CDN URL. // Default: "https://cdn.jsdelivr.net/npm/frankenphp-hot-reload@1.0.1/+esm"
 *     preserve_selectors?: list<scalar|Param|null>,
 *     preserve_observe?: bool|Param, // When true, the preserve boot script also uses MutationObserver for late-injected toolbar nodes. // Default: true
 *     csp_nonce_request_attribute?: scalar|Param|null, // Request attribute name that holds the CSP nonce (e.g. "_csp_nonce"). Applied to the inline preserve boot script. // Default: null
 *     csp_augment_script_src?: bool|Param, // When true, append CDN hosts to an existing Content-Security-Policy script-src on the response after injection. // Default: true
 *     csp_script_src_hosts?: list<scalar|Param|null>,
 * }
 * @psalm-type NowoTwigInspectorConfig = array{
 *     enabled_extensions?: list<scalar|Param|null>,
 *     excluded_templates?: list<scalar|Param|null>,
 *     excluded_blocks?: list<scalar|Param|null>,
 *     enable_metrics?: bool|Param, // Enable collection of template usage metrics in DataCollector // Default: true
 *     inject_on_sub_requests?: bool|Param, // When true, inject comments also during sub-requests (e.g. when main content is rendered as fragment). Enable if all templates show "sub-request" and none get inspected. // Default: false
 *     cookie_name?: scalar|Param|null, // Name of the cookie used to enable/disable the inspector // Default: "twig_inspector_is_active"
 *     max_injection_depth?: int|Param, // Maximum nesting depth for comment injection (0 = unlimited). Reduces overhead on very deep template trees. // Default: 0
 *     excluded_templates_regex?: list<scalar|Param|null>,
 *     excluded_templates_prefixes?: list<scalar|Param|null>,
 *     excluded_blocks_regex?: list<scalar|Param|null>,
 *     overlay_theme?: scalar|Param|null, // Overlay theme: "light", "dark", or "auto" (follow system preference). // Default: "light"
 *     overlay_compact?: bool|Param, // Use compact tooltip style for the overlay. // Default: false
 *     reduced_motion?: bool|Param, // Respect reduced motion (accessibility). When true or system prefers-reduced-motion, animations are minimized. // Default: false
 *     keyboard_shortcut?: scalar|Param|null, // Keyboard shortcut to toggle inspector (e.g. "Ctrl+Shift+T"). Empty to disable. // Default: "Ctrl+Shift+T"
 * }
 * @psalm-type WebProfilerConfig = array{
 *     toolbar?: bool|array{ // Profiler toolbar configuration
 *         enabled?: bool|Param, // Default: false
 *         ajax_replace?: bool|Param, // Replace toolbar on AJAX requests // Default: false
 *     },
 *     intercept_redirects?: bool|Param, // Default: false
 *     excluded_ajax_paths?: scalar|Param|null, // Default: "^/((index|app(_[\\w]+)?)\\.php/)?_wdt"
 * }
 * @psalm-type DebugConfig = array{
 *     max_items?: int|Param, // Max number of displayed items past the first level, -1 means no limit. // Default: 2500
 *     min_depth?: int|Param, // Minimum tree depth to clone all the items, 1 is default. // Default: 1
 *     max_string_length?: int|Param, // Max length of displayed strings, -1 means no limit. // Default: -1
 *     dump_destination?: scalar|Param|null, // A stream URL where dumps should be written to. // Default: null
 *     theme?: "dark"|"light"|Param, // Changes the color of the dump() output when rendered directly on the templating. "dark" (default) or "light". // Default: "dark"
 * }
 * @psalm-type NowoTagInputConfig = array{
 *     value_format?: "array"|"string"|Param, // Default: "array"
 *     trim?: bool|Param, // Default: true
 *     pattern?: scalar|Param|null, // Regex source without delimiters (shared with the Tagify client); must compile. // Default: null
 *     whitelist?: list<scalar|Param|null>,
 *     duplicates?: bool|Param, // Default: false
 *     max_tags?: int|Param, // Maximum tags accepted from the client (fail-closed on overflow). // Default: 50
 *     dropdown_enabled?: bool|Param, // Default: true
 *     placeholder?: scalar|Param|null, // Default placeholder: a translation key or literal text, translated with the field translation_domain. Empty string disables it. // Default: "tag.placeholder"
 *     form_theme?: "form_div_layout.html.twig"|"form_table_layout.html.twig"|"bootstrap_5_layout.html.twig"|"bootstrap_5_horizontal_layout.html.twig"|"bootstrap_4_layout.html.twig"|"bootstrap_4_horizontal_layout.html.twig"|"bootstrap_3_layout.html.twig"|"bootstrap_3_horizontal_layout.html.twig"|"foundation_5_layout.html.twig"|"foundation_6_layout.html.twig"|"tailwind_2_layout.html.twig"|Param, // Default: "form_div_layout.html.twig"
 * }
 * @psalm-type NowoTiptapEditorConfig = array{
 *     default_profile?: scalar|Param|null, // Profile name used when the form field omits the "config" option (form option key remains "config" for BC). // Default: "default"
 *     stylesheet?: bool|Param, // 1.7: when true the widget links tiptap-editor.css (asset package nowo_tiptap_editor, with the request attribute csp_nonce as nonce). Set false when your layout loads that file (or your own styles) itself. // Default: true
 *     html_sanitizer?: scalar|Param|null, // Optional service id implementing TiptapHtmlSanitizerInterface, or the special value "allowlist" for the built-in allowlist sanitizer. Null (default) disables server-side sanitization (BC). // Default: null
 *     profiles?: array<string, array{ // Default: []
 *         toolbar?: bool|Param, // When true, the frontend shows a compact formatting toolbar (bold, lists, undo, etc.). // Default: true
 *         min_height?: scalar|Param|null, // Default CSS min-height for the editor surface (e.g. 240px, 12rem). // Default: "240px"
 *         form_theme?: scalar|Param|null, // Base Symfony form layout (must match twig.form_themes in your app). // Default: "form_div_layout.html.twig"
 *         debug?: bool|Param, // When true, the browser console receives detailed logs from the bundle script. // Default: false
 *         variant?: scalar|Param|null, // Editor UX preset: default, simple, notion, agent, headless (see EditorVariant). // Default: "default"
 *         toolbar_display?: "text"|"icon"|Param, // 1.7: toolbar buttons as text glyphs (text, 1.x look) or icons (icon: built-in SVG or the host <template data-tiptap-icon> markup); both get translated aria-label / title. // Default: "text"
 *         theme?: scalar|Param|null, // Chrome palette: light, dark, or auto (follows prefers-color-scheme). // Default: "light"
 *     }>,
 *     ...<string, mixed>
 * }
 * @psalm-type TwigExtraConfig = array{
 *     cache?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     html?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     markdown?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     intl?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     cssinliner?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     inky?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     string?: bool|array{
 *         enabled?: bool|Param, // Default: true
 *     },
 *     commonmark?: array{
 *         renderer?: array{ // Array of options for rendering HTML.
 *             block_separator?: scalar|Param|null,
 *             inner_separator?: scalar|Param|null,
 *             soft_break?: scalar|Param|null,
 *         },
 *         html_input?: "strip"|"allow"|"escape"|Param, // How to handle HTML input.
 *         allow_unsafe_links?: bool|Param, // Remove risky link and image URLs by setting this to false. // Default: true
 *         max_nesting_level?: int|Param, // The maximum nesting level for blocks. // Default: 9223372036854775807
 *         max_delimiters_per_line?: int|Param, // The maximum number of strong/emphasis delimiters per line. // Default: 9223372036854775807
 *         slug_normalizer?: array{ // Array of options for configuring how URL-safe slugs are created.
 *             instance?: mixed,
 *             max_length?: int|Param, // Default: 255
 *             unique?: mixed,
 *         },
 *         commonmark?: array{ // Array of options for configuring the CommonMark core extension.
 *             enable_em?: bool|Param, // Default: true
 *             enable_strong?: bool|Param, // Default: true
 *             use_asterisk?: bool|Param, // Default: true
 *             use_underscore?: bool|Param, // Default: true
 *             unordered_list_markers?: list<scalar|Param|null>,
 *         },
 *         ...<string, mixed>
 *     },
 * }
 * @psalm-type ConfigType = array{
 *     imports?: ImportsConfig,
 *     parameters?: ParametersConfig,
 *     services?: ServicesConfig,
 *     framework?: FrameworkConfig,
 *     doctrine?: DoctrineConfig,
 *     doctrine_migrations?: DoctrineMigrationsConfig,
 *     security?: SecurityConfig,
 *     twig?: TwigConfig,
 *     nowo_time_track?: NowoTimeTrackConfig,
 *     nowo_task_board?: NowoTaskBoardConfig,
 *     nowo_ui_kit?: NowoUiKitConfig,
 *     nowo_form_kit?: NowoFormKitConfig,
 *     nowo_tag_input?: NowoTagInputConfig,
 *     nowo_tiptap_editor?: NowoTiptapEditorConfig,
 *     twig_extra?: TwigExtraConfig,
 *     "when@dev"?: array{
 *         imports?: ImportsConfig,
 *         parameters?: ParametersConfig,
 *         services?: ServicesConfig,
 *         framework?: FrameworkConfig,
 *         doctrine?: DoctrineConfig,
 *         doctrine_migrations?: DoctrineMigrationsConfig,
 *         security?: SecurityConfig,
 *         twig?: TwigConfig,
 *         nowo_time_track?: NowoTimeTrackConfig,
 *         nowo_task_board?: NowoTaskBoardConfig,
 *         nowo_ui_kit?: NowoUiKitConfig,
 *         nowo_form_kit?: NowoFormKitConfig,
 *         nowo_hot_reload?: NowoHotReloadConfig,
 *         nowo_twig_inspector?: NowoTwigInspectorConfig,
 *         web_profiler?: WebProfilerConfig,
 *         debug?: DebugConfig,
 *         nowo_tag_input?: NowoTagInputConfig,
 *         nowo_tiptap_editor?: NowoTiptapEditorConfig,
 *         twig_extra?: TwigExtraConfig,
 *     },
 *     "when@prod"?: array{
 *         imports?: ImportsConfig,
 *         parameters?: ParametersConfig,
 *         services?: ServicesConfig,
 *         framework?: FrameworkConfig,
 *         doctrine?: DoctrineConfig,
 *         doctrine_migrations?: DoctrineMigrationsConfig,
 *         security?: SecurityConfig,
 *         twig?: TwigConfig,
 *         nowo_time_track?: NowoTimeTrackConfig,
 *         nowo_task_board?: NowoTaskBoardConfig,
 *         nowo_ui_kit?: NowoUiKitConfig,
 *         nowo_form_kit?: NowoFormKitConfig,
 *         nowo_tag_input?: NowoTagInputConfig,
 *         nowo_tiptap_editor?: NowoTiptapEditorConfig,
 *         twig_extra?: TwigExtraConfig,
 *     },
 *     "when@test"?: array{
 *         imports?: ImportsConfig,
 *         parameters?: ParametersConfig,
 *         services?: ServicesConfig,
 *         framework?: FrameworkConfig,
 *         doctrine?: DoctrineConfig,
 *         doctrine_migrations?: DoctrineMigrationsConfig,
 *         security?: SecurityConfig,
 *         twig?: TwigConfig,
 *         nowo_time_track?: NowoTimeTrackConfig,
 *         nowo_task_board?: NowoTaskBoardConfig,
 *         nowo_ui_kit?: NowoUiKitConfig,
 *         nowo_form_kit?: NowoFormKitConfig,
 *         nowo_hot_reload?: NowoHotReloadConfig,
 *         nowo_twig_inspector?: NowoTwigInspectorConfig,
 *         web_profiler?: WebProfilerConfig,
 *         nowo_tag_input?: NowoTagInputConfig,
 *         nowo_tiptap_editor?: NowoTiptapEditorConfig,
 *         twig_extra?: TwigExtraConfig,
 *     },
 *     ...<string, ExtensionType|array{ // extra keys must follow the when@%env% pattern or match an extension alias
 *         imports?: ImportsConfig,
 *         parameters?: ParametersConfig,
 *         services?: ServicesConfig,
 *         ...<string, ExtensionType>,
 *     }>
 * }
 */
final class App
{
    /**
     * @param ConfigType $config
     *
     * @psalm-return ConfigType
     */
    public static function config(array $config): array
    {
        /** @var ConfigType $config */
        $config = AppReference::config($config);

        return $config;
    }
}

namespace Symfony\Component\Routing\Loader\Configurator;

/**
 * This class provides array-shapes for configuring the routes of an application.
 *
 * Example:
 *
 *     ```php
 *     // config/routes.php
 *     namespace Symfony\Component\Routing\Loader\Configurator;
 *
 *     return Routes::config([
 *         'controllers' => [
 *             'resource' => 'routing.controllers',
 *         ],
 *     ]);
 *     ```
 *
 * @psalm-type RouteConfig = array{
 *     path: string|array<string,string>,
 *     controller?: string,
 *     methods?: string|list<string>,
 *     requirements?: array<string,string>,
 *     defaults?: array<string,mixed>,
 *     options?: array<string,mixed>,
 *     host?: string|array<string,string>,
 *     schemes?: string|list<string>,
 *     condition?: string,
 *     locale?: string,
 *     format?: string,
 *     utf8?: bool,
 *     stateless?: bool,
 * }
 * @psalm-type ImportConfig = array{
 *     resource: string,
 *     type?: string,
 *     exclude?: string|list<string>,
 *     prefix?: string|array<string,string>,
 *     name_prefix?: string,
 *     trailing_slash_on_root?: bool,
 *     controller?: string,
 *     methods?: string|list<string>,
 *     requirements?: array<string,string>,
 *     defaults?: array<string,mixed>,
 *     options?: array<string,mixed>,
 *     host?: string|array<string,string>,
 *     schemes?: string|list<string>,
 *     condition?: string,
 *     locale?: string,
 *     format?: string,
 *     utf8?: bool,
 *     stateless?: bool,
 * }
 * @psalm-type AliasConfig = array{
 *     alias: string,
 *     deprecated?: array{package:string, version:string, message?:string},
 * }
 * @psalm-type RoutesConfig = array{
 *     "when@dev"?: array<string, RouteConfig|ImportConfig|AliasConfig>,
 *     "when@prod"?: array<string, RouteConfig|ImportConfig|AliasConfig>,
 *     "when@test"?: array<string, RouteConfig|ImportConfig|AliasConfig>,
 *     ...<string, RouteConfig|ImportConfig|AliasConfig>
 * }
 */
final class Routes
{
    /**
     * @param RoutesConfig $config
     *
     * @psalm-return RoutesConfig
     */
    public static function config(array $config): array
    {
        return $config;
    }
}
