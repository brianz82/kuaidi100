<?php

namespace spec\Homer\Logistics\Kuaidi100;

use GuzzleHttp\RequestOptions;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Homer\Logistics\Kuaidi100\Service;

/**
 * Spec for unit testing Kuaidi100 Logistics Service
 */
class ServiceSpec extends ObjectBehavior
{
    function let(ClientInterface $client)
    {
        $this->beAnInstanceOf(Service::class, [
            'KEY_FROM_KUAIDI100', [
                'notification_url' => 'http://host/notifications'
            ], $client
        ]);
    }
    
    //=====================================
    //          track/subscribe
    //=====================================
    function it_tracks(ClientInterface $client)
    {
        $client->request('POST', Service::SUBSCRIBE_URL, Argument::that(function (array $request) {
            $request = $request[RequestOptions::FORM_PARAMS];

            if ($request['schema'] != 'json') return false;

            $param = json_decode($request['param']);
            return $param->number == 'WAYBILL_NO' &&
                   $param->key == 'KEY_FROM_KUAIDI100' &&
                   $param->parameters->callbackurl == 'http://host/notifications' &&
                   $param->parameters->salt == 'WAYBILL_NO';

        }))->shouldBeCalledTimes(1)
           ->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/track_success.json')));

        $this->track('WAYBILL_NO')->shouldBe(true);
    }

    function it_tracks_with_notification_url_for_each(ClientInterface $client)
    {
        $client->request('POST', Service::SUBSCRIBE_URL, Argument::that(function (array $request) {
            $request = $request[RequestOptions::FORM_PARAMS];

            if ($request['schema'] != 'json') return false;

            $param = json_decode($request['param']);
            return $param->number == 'WAYBILL_NO' &&
                   $param->parameters->callbackurl == 'http://host/notifications/dedicated';
        }))->shouldBeCalledTimes(1)
            ->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/track_success.json')));

        $this->track('WAYBILL_NO', [
            'notification_url' => 'http://host/notifications/dedicated'
        ])->shouldBe(true);
    }

    function it_gets_error_with_unsupported_company_when_tracking(ClientInterface $client)
    {
        $client->request('POST', Service::SUBSCRIBE_URL, Argument::cetera())->shouldBeCalledTimes(1)
            ->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/track_unsupported_company.json')));

        $this->shouldThrow(new \Exception('拒绝订阅的快递公司', 701))->duringTrack('WAYBILL_NO');
    }


    //=====================================
    //        handle waybill updated
    //=====================================
    function it_handles_waybill_updated()
    {
        $this->handleWaybillUpdated(file_get_contents(__DIR__ . '/data/waybill_updated.txt'), function ($logistics) {
            $tracking = $logistics->tracking;
            assert_equals('polling', $tracking->status);
            assert_true(!$tracking->fake);

            $domestic = $logistics->domestic;
            assert_equals('0', $domestic->state);
            assert_true(!$domestic->signed);
            assert_equals('V030344422', $domestic->waybillNo);
            assert_equals('yuantong', $domestic->company);

            $items = $domestic->items;
            assert_count(2, $items);
            assert_equals('2012-08-28 16:33:19', $items[0]->time);
            assert_equals('2012-08-27 23:22:42', $items[1]->time);
        });
    }

    function it_rejects_bad_signature_while_handling_waybill_updated()
    {
        $this->shouldThrow(new \InvalidArgumentException('非法通知消息(Forged)'))->duringHandleWaybillUpdated(file_get_contents(__DIR__ . '/data/waybill_updated_bad_sign.txt'), function ($logistics) {
            assert_true(false, 'Should not reach here');
        });
    }
}
