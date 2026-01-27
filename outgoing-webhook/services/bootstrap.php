<?php
declare(strict_types=1);

// Исключения
require_once __DIR__ . '/Exceptions/InvalidRequestException.php';
require_once __DIR__ . '/Exceptions/AuthenticationException.php';
require_once __DIR__ . '/Exceptions/ProcessingException.php';

// Базовые сервисы
require_once __DIR__ . '/Config/ConfigService.php';
require_once __DIR__ . '/Core/FilesystemService.php';
require_once __DIR__ . '/Http/RequestService.php';
require_once __DIR__ . '/Security/AccessService.php';
require_once __DIR__ . '/Identity/EntityIdentityService.php';
require_once __DIR__ . '/Logging/ErrorService.php';
require_once __DIR__ . '/Logging/LogValueFormatter.php';
require_once __DIR__ . '/Logging/QueueStepLogger.php';
require_once __DIR__ . '/Rest/RestService.php';
require_once __DIR__ . '/Dicts/DictCacheService.php';
require_once __DIR__ . '/Enrichment/ValueComparator.php';
require_once __DIR__ . '/Enrichment/StateStorage.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/EntityHandlerInterface.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/DefaultEntityHandler.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/DealHandler.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/LeadHandler.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/SmartProcessHandler.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/TaskHandler.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/UserHandler.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/ProjectHandler.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/CrmUserFieldHandler.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/ContactHandler.php';
require_once __DIR__ . '/Enrichment/EntityHandlers/CompanyHandler.php';
require_once __DIR__ . '/Enrichment/EnrichmentService.php';
require_once __DIR__ . '/Queue/QueueJob.php';
require_once __DIR__ . '/Queue/QueueService.php';
require_once __DIR__ . '/Queue/DatabaseQueueService.php';
require_once __DIR__ . '/Queue/JobStateService.php';
require_once __DIR__ . '/Queue/QueueRunner.php';
require_once __DIR__ . '/Task/TaskDetailsService.php';
require_once __DIR__ . '/Task/TaskFilesService.php';
require_once __DIR__ . '/Crm/DealFileService.php';
require_once __DIR__ . '/Task/Comment/CommentFetcher.php';
require_once __DIR__ . '/Task/Comment/CommentBuilder.php';
require_once __DIR__ . '/Task/Comment/CommentFormatter.php';
require_once __DIR__ . '/Task/Comment/CommentWriter.php';
require_once __DIR__ . '/Task/Comment/ActivityFirstProcessor.php';
require_once __DIR__ . '/Task/Activity/ActivityConfigService.php';
require_once __DIR__ . '/Task/Comment/ActivityProcessor.php';
require_once __DIR__ . '/Task/CommentDetailsService.php';

// Database сервисы
require_once __DIR__ . '/Database/DatabaseService.php';
require_once __DIR__ . '/Database/Repositories/EventRepository.php';
require_once __DIR__ . '/Database/Repositories/QueueRepository.php';
require_once __DIR__ . '/Database/Repositories/TaskDetailsRepository.php';
require_once __DIR__ . '/Database/Repositories/CommentDetailsRepository.php';
require_once __DIR__ . '/Database/Repositories/EntityStateRepository.php';

// Контейнер и компоненты рефакторинга
require_once __DIR__ . '/Container/ServiceContainer.php';
require_once __DIR__ . '/Http/RequestValidator.php';
require_once __DIR__ . '/Security/AuthMiddleware.php';
require_once __DIR__ . '/Event/EventProcessor.php';
require_once __DIR__ . '/Event/DatabaseEventProcessor.php';
require_once __DIR__ . '/Event/Handlers/TaskEventHandler.php';
require_once __DIR__ . '/Event/Handlers/CommentEventHandler.php';
require_once __DIR__ . '/Event/Handlers/DealEventHandler.php';
