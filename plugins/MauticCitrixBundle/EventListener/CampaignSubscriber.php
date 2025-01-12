<?php

namespace MauticPlugin\MauticCitrixBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use MauticPlugin\MauticCitrixBundle\CitrixEvents;
use MauticPlugin\MauticCitrixBundle\Entity\CitrixEventTypes;
use MauticPlugin\MauticCitrixBundle\Form\Type\CitrixCampaignActionType;
use MauticPlugin\MauticCitrixBundle\Form\Type\CitrixCampaignEventType;
use MauticPlugin\MauticCitrixBundle\Helper\CitrixHelper;
use MauticPlugin\MauticCitrixBundle\Helper\CitrixProducts;
use MauticPlugin\MauticCitrixBundle\Model\CitrixModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class CampaignSubscriber implements EventSubscriberInterface
{
    use CitrixRegistrationTrait;
    use CitrixStartTrait;

    /**
     * @var CitrixModel
     */
    private $citrixModel;

    /**
     * ヽ(ಠ_ಠ)ノ Used in the CitrixStartTrait.
     *
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * ヽ(ಠ_ಠ)ノ Used in the CitrixStartTrait.
     *
     * @var Environment
     */
    private $twig;

    public function __construct(
        CitrixModel $citrixModel,
        TranslatorInterface $translator,
        Environment $twig
    ) {
        $this->citrixModel = $citrixModel;
        $this->translator  = $translator;
        $this->twig        = $twig;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD       => ['onCampaignBuild', 0],
            CitrixEvents::ON_CITRIX_WEBINAR_EVENT   => ['onWebinarEvent', 0],
            CitrixEvents::ON_CITRIX_MEETING_EVENT   => ['onMeetingEvent', 0],
            CitrixEvents::ON_CITRIX_TRAINING_EVENT  => ['onTrainingEvent', 0],
            CitrixEvents::ON_CITRIX_ASSIST_EVENT    => ['onAssistEvent', 0],
            CitrixEvents::ON_CITRIX_WEBINAR_ACTION  => ['onWebinarAction', 0],
            CitrixEvents::ON_CITRIX_MEETING_ACTION  => ['onMeetingAction', 0],
            CitrixEvents::ON_CITRIX_TRAINING_ACTION => ['onTrainingAction', 0],
            CitrixEvents::ON_CITRIX_ASSIST_ACTION   => ['onAssistAction', 0],
        ];
    }

    /* Actions */

    public function onWebinarAction(CampaignExecutionEvent $event)
    {
        $event->setResult($this->onCitrixAction(CitrixProducts::GOTOWEBINAR, $event));
    }

    public function onMeetingAction(CampaignExecutionEvent $event)
    {
        $event->setResult($this->onCitrixAction(CitrixProducts::GOTOMEETING, $event));
    }

    public function onTrainingAction(CampaignExecutionEvent $event)
    {
        $event->setResult($this->onCitrixAction(CitrixProducts::GOTOTRAINING, $event));
    }

    public function onAssistAction(CampaignExecutionEvent $event)
    {
        $event->setResult($this->onCitrixAction(CitrixProducts::GOTOASSIST, $event));
    }

    /**
     * @param string $product
     *
     * @return bool
     */
    public function onCitrixAction($product, CampaignExecutionEvent $event)
    {
        if (!CitrixProducts::isValidValue($product)) {
            return false;
        }

        // get firstName, lastName and email from keys for sender email
        $config   = $event->getConfig();
        $criteria = $config['event-criteria-'.$product];
        /** @var array $list */
        $list     = $config[$product.'-list'];
        $actionId = 'citrix.action.'.$product;
        try {
            $productlist = CitrixHelper::getCitrixChoices($product);
            $products    = [];

            foreach ($list as $productId) {
                if (array_key_exists(
                    $productId,
                    $productlist
                )) {
                    $products[] = [
                        'productId'    => $productId,
                        'productTitle' => $productlist[$productId],
                    ];
                }
            }
            if (in_array($criteria, ['webinar_register', 'training_register'], true)) {
                $this->registerProduct($product, $event->getLead(), $products);
            } else {
                if (in_array($criteria, ['assist_screensharing', 'training_start', 'meeting_start'], true)) {
                    $emailId = $config['template'];
                    $this->startProduct($product, $event->getLead(), $products, $emailId, $actionId);
                }
            }
        } catch (\Exception $ex) {
            CitrixHelper::log('onCitrixAction - '.$product.': '.$ex->getMessage());
        }

        return true;
    }

    /* Events */

    public function onWebinarEvent(CampaignExecutionEvent $event)
    {
        $event->setResult($this->onCitrixEvent(CitrixProducts::GOTOWEBINAR, $event));
    }

    public function onMeetingEvent(CampaignExecutionEvent $event)
    {
        $event->setResult($this->onCitrixEvent(CitrixProducts::GOTOMEETING, $event));
    }

    public function onTrainingEvent(CampaignExecutionEvent $event)
    {
        $event->setResult($this->onCitrixEvent(CitrixProducts::GOTOTRAINING, $event));
    }

    public function onAssistEvent(CampaignExecutionEvent $event)
    {
        $event->setResult($this->onCitrixEvent(CitrixProducts::GOTOASSIST, $event));
    }

    /**
     * @param string $product
     *
     * @return bool
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     */
    public function onCitrixEvent($product, CampaignExecutionEvent $event)
    {
        if (!CitrixProducts::isValidValue($product)) {
            return false;
        }

        $config   = $event->getConfig();
        $criteria = $config['event-criteria-'.$product];
        $list     = $config[$product.'-list'];
        $isAny    = in_array('ANY', $list, true);
        $email    = $event->getLead()->getEmail();

        if ('registeredToAtLeast' === $criteria) {
            $counter = $this->citrixModel->countEventsBy(
                $product,
                $email,
                CitrixEventTypes::REGISTERED,
                $isAny ? [] : $list
            );
        } else {
            if ('attendedToAtLeast' === $criteria) {
                $counter = $this->citrixModel->countEventsBy(
                    $product,
                    $email,
                    CitrixEventTypes::ATTENDED,
                    $isAny ? [] : $list
                );
            } else {
                return false;
            }
        }

        return $counter > 0;
    }

    public function onCampaignBuild(CampaignBuilderEvent $event)
    {
        $activeProducts = [];
        foreach (CitrixProducts::toArray() as $p) {
            if (CitrixHelper::isAuthorized('Goto'.$p)) {
                $activeProducts[] = $p;
            }
        }
        if (0 === count($activeProducts)) {
            return;
        }

        $eventNames = [
            CitrixProducts::GOTOWEBINAR  => CitrixEvents::ON_CITRIX_WEBINAR_EVENT,
            CitrixProducts::GOTOMEETING  => CitrixEvents::ON_CITRIX_MEETING_EVENT,
            CitrixProducts::GOTOTRAINING => CitrixEvents::ON_CITRIX_TRAINING_EVENT,
            CitrixProducts::GOTOASSIST   => CitrixEvents::ON_CITRIX_ASSIST_EVENT,
        ];

        $actionNames = [
            CitrixProducts::GOTOWEBINAR  => CitrixEvents::ON_CITRIX_WEBINAR_ACTION,
            CitrixProducts::GOTOMEETING  => CitrixEvents::ON_CITRIX_MEETING_ACTION,
            CitrixProducts::GOTOTRAINING => CitrixEvents::ON_CITRIX_TRAINING_ACTION,
            CitrixProducts::GOTOASSIST   => CitrixEvents::ON_CITRIX_ASSIST_ACTION,
        ];

        foreach ($activeProducts as $product) {
            $event->addCondition(
                'citrix.event.'.$product,
                [
                    'label'           => 'plugin.citrix.campaign.event.'.$product.'.label',
                    'formType'        => CitrixCampaignEventType::class,
                    'formTypeOptions' => [
                        'attr' => [
                            'data-product' => $product,
                        ],
                    ],
                    'eventName'      => $eventNames[$product],
                    'channel'        => 'citrix',
                    'channelIdField' => $product.'-list',
                ]
            );

            $event->addAction(
                'citrix.action.'.$product,
                [
                    'label'           => 'plugin.citrix.campaign.action.'.$product.'.label',
                    'formType'        => CitrixCampaignActionType::class,
                    'formTypeOptions' => [
                        'attr' => [
                            'data-product' => $product,
                        ],
                    ],
                    'eventName'      => $actionNames[$product],
                    'channel'        => 'citrix',
                    'channelIdField' => $product.'-list',
                ]
            );
        }
    }
}
