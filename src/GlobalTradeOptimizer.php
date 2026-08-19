<?php
declare(strict_types=1);

/**
 * V8.42 Directed Trade Opportunity Builder
 *
 * A player-facing opportunity exists when:
 *   1. Requester A needs card X.
 *   2. Donor B has an extra card X.
 *   3. Requester A has at least one extra card Y in the SAME category as X.
 *
 * B does NOT have to need Y.
 *
 * This matches the actual player action: A can request X from B and offer any
 * same-group extra Y back. The opportunity is shown to A because A benefits
 * from it. B is not bothered with it unless B independently has a request that
 * A can satisfy.
 *
 * Admin receives the complete graph, including one-way opportunity directions.
 * Nothing is allocated/reserved, so the same extra may appear in several paths.
 */
final class GlobalTradeOptimizer
{
    public function __construct(private PDO $pdo) {}

    public function optimize(): array
    {
        $cardCount=(int)$this->pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn();

        $playerRows=$this->pdo->query(
            'SELECT p.id,p.display_name,COUNT(DISTINCT pc.card_id) AS saved_card_rows
             FROM players p
             LEFT JOIN player_cards pc ON pc.player_id=p.id
             GROUP BY p.id,p.display_name
             ORDER BY LOWER(p.display_name),p.id'
        )->fetchAll();

        $eligible=[];
        $excluded=[];
        foreach($playerRows as $row){
            $p=[
                'id'=>(int)$row['id'],
                'name'=>(string)$row['display_name'],
                'saved_card_rows'=>(int)$row['saved_card_rows'],
            ];
            if($cardCount>0 && $p['saved_card_rows']===$cardCount){
                $eligible[$p['id']]=$p;
            }else{
                $excluded[]=$p;
            }
        }

        if(!$eligible || $cardCount===0){
            return $this->emptyResult($cardCount,$eligible,$excluded);
        }

        $ids=array_keys($eligible);
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$this->pdo->prepare(
            "SELECT p.id AS player_id,c.id AS card_id,c.name AS card_name,
                    c.category,c.required_qty,pc.owned_qty
             FROM players p
             JOIN player_cards pc ON pc.player_id=p.id
             JOIN cards c ON c.id=pc.card_id
             WHERE p.id IN ($ph)
             ORDER BY c.id,p.id"
        );
        $stmt->execute($ids);

        $cards=[];
        $needs=[];
        $extras=[];
        $totalNeed=0;

        foreach($stmt->fetchAll() as $row){
            $pid=(int)$row['player_id'];
            $cid=(int)$row['card_id'];
            $required=(int)$row['required_qty'];
            $owned=(int)$row['owned_qty'];

            $cards[$cid]=[
                'id'=>$cid,
                'name'=>(string)$row['card_name'],
                'category'=>(string)$row['category'],
            ];

            if($owned<$required){
                $qty=$required-$owned;
                $needs[$pid][$cid]=$qty;
                $totalNeed+=$qty;
            }elseif($owned>$required){
                $extras[$pid][$cid]=$owned-$required;
            }
        }

        $extrasByCategory=[];
        foreach($extras as $pid=>$rows){
            foreach($rows as $cid=>$qty){
                $category=$cards[$cid]['category'];
                $extrasByCategory[$pid][$category][]=[
                    'card_id'=>(int)$cid,
                    'card_name'=>$cards[$cid]['name'],
                    'category'=>$category,
                    'qty'=>(int)$qty,
                ];
            }
        }

        $relationships=[];
        $opportunities=[];
        $reachableNeed=[];
        $requestersWithOpportunity=[];

        foreach($needs as $requesterId=>$playerNeeds){
            foreach($playerNeeds as $neededCardId=>$neededQty){
                $category=$cards[$neededCardId]['category'];
                $offerChoices=$extrasByCategory[$requesterId][$category]??[];

                // Requester must have something legal to put on the other side.
                if(!$offerChoices) continue;

                foreach($eligible as $donorId=>$donor){
                    $donorId=(int)$donorId;
                    if($donorId===(int)$requesterId) continue;

                    $donorQty=(int)($extras[$donorId][$neededCardId]??0);
                    if($donorQty<=0) continue;

                    $opp=[
                        'opportunity_no'=>count($opportunities)+1,
                        'requester_id'=>(int)$requesterId,
                        'requester_name'=>$eligible[$requesterId]['name'],
                        'donor_id'=>$donorId,
                        'donor_name'=>$donor['name'],
                        'category'=>$category,
                        'receive_card_id'=>(int)$neededCardId,
                        'receive_card_name'=>$cards[$neededCardId]['name'],
                        'receive_qty'=>min((int)$neededQty,$donorQty),
                        'offer_choices'=>$offerChoices,
                        'offer_choice_count'=>count($offerChoices),
                    ];
                    $opportunities[]=$opp;
                    $reachableNeed[$requesterId][$neededCardId]=true;
                    $requestersWithOpportunity[$requesterId]=true;

                    $pairKey=$this->pairKey((int)$requesterId,$donorId);
                    if(!isset($relationships[$pairKey])){
                        $a=min((int)$requesterId,$donorId);
                        $b=max((int)$requesterId,$donorId);
                        $relationships[$pairKey]=[
                            'pair_key'=>$pairKey,
                            'player_a_id'=>$a,
                            'player_a_name'=>$eligible[$a]['name'],
                            'player_b_id'=>$b,
                            'player_b_name'=>$eligible[$b]['name'],
                            'opportunities'=>[],
                            'trade_groups'=>[],
                            'transfers'=>[],
                            'directions'=>[],
                            'trade_count'=>0,
                            'unit_count'=>0,
                            'reciprocal'=>false,
                        ];
                    }

                    $r=&$relationships[$pairKey];
                    $r['opportunities'][]=$opp;
                    $r['trade_count']++;
                    $r['unit_count']+=(int)$opp['receive_qty'];

                    $directionKey=$requesterId.'>'.$donorId;
                    $r['directions'][$directionKey]=[
                        'requester_id'=>(int)$requesterId,
                        'requester_name'=>$eligible[$requesterId]['name'],
                        'donor_id'=>$donorId,
                        'donor_name'=>$donor['name'],
                    ];

                    $groupKey=$directionKey.'|'.$category;
                    if(!isset($r['trade_groups'][$groupKey])){
                        $r['trade_groups'][$groupKey]=[
                            'category'=>$category,
                            'requester_id'=>(int)$requesterId,
                            'requester_name'=>$eligible[$requesterId]['name'],
                            'donor_id'=>$donorId,
                            'donor_name'=>$donor['name'],
                            'opportunities'=>[],
                            'transfers'=>[],
                            'trades'=>[],
                            'trade_count'=>0,
                            'unit_count'=>0,
                        ];
                    }

                    $g=&$r['trade_groups'][$groupKey];
                    $g['opportunities'][]=$opp;
                    $g['trade_count']++;
                    $g['unit_count']+=(int)$opp['receive_qty'];

                    // Incoming/requested card. This drives graph/card details.
                    $incoming=[
                        'from_player_id'=>$donorId,
                        'from_player_name'=>$donor['name'],
                        'to_player_id'=>(int)$requesterId,
                        'to_player_name'=>$eligible[$requesterId]['name'],
                        'card_id'=>(int)$neededCardId,
                        'card_name'=>$cards[$neededCardId]['name'],
                        'category'=>$category,
                        'qty'=>(int)$opp['receive_qty'],
                    ];
                    $g['transfers'][]=$incoming;
                    $r['transfers'][]=$incoming;

                    // Add all legal cards the requester could offer. Donor need
                    // is deliberately irrelevant.
                    foreach($offerChoices as $offer){
                        $outgoing=[
                            'from_player_id'=>(int)$requesterId,
                            'from_player_name'=>$eligible[$requesterId]['name'],
                            'to_player_id'=>$donorId,
                            'to_player_name'=>$donor['name'],
                            'card_id'=>(int)$offer['card_id'],
                            'card_name'=>$offer['card_name'],
                            'category'=>$category,
                            'qty'=>(int)$offer['qty'],
                        ];
                        $g['transfers'][]=$outgoing;

                        // Compatibility shape used by the Admin proposal UI.
                        $a=$r['player_a_id'];
                        $requesterIsA=((int)$requesterId===$a);
                        $g['trades'][]=[
                            'category'=>$category,
                            'player_a_id'=>$r['player_a_id'],
                            'player_a_name'=>$r['player_a_name'],
                            'player_b_id'=>$r['player_b_id'],
                            'player_b_name'=>$r['player_b_name'],
                            'player_a_gives_card_id'=>$requesterIsA
                                ? (int)$offer['card_id']
                                : (int)$neededCardId,
                            'player_a_gives_card_name'=>$requesterIsA
                                ? $offer['card_name']
                                : $cards[$neededCardId]['name'],
                            'player_b_gives_card_id'=>$requesterIsA
                                ? (int)$neededCardId
                                : (int)$offer['card_id'],
                            'player_b_gives_card_name'=>$requesterIsA
                                ? $cards[$neededCardId]['name']
                                : $offer['card_name'],
                            'requester_id'=>(int)$requesterId,
                            'donor_id'=>$donorId,
                        ];
                    }
                    unset($g,$r);
                }
            }
        }

        foreach($relationships as &$r){
            $r['directions']=array_values($r['directions']);
            $r['reciprocal']=count($r['directions'])>1;
            $r['trade_groups']=array_values($r['trade_groups']);

            // Deduplicate relationship-level transfer display lines.
            $dedup=[];
            foreach($r['transfers'] as $t){
                $key=$t['from_player_id'].':'.$t['to_player_id'].':'.
                    $t['category'].':'.$t['card_id'];
                if(!isset($dedup[$key])){
                    $dedup[$key]=$t;
                }else{
                    $dedup[$key]['qty']=max(
                        (int)$dedup[$key]['qty'],
                        (int)$t['qty']
                    );
                }
            }
            $r['transfers']=array_values($dedup);
        }
        unset($r);

        uasort($relationships,static function(array $a,array $b):int{
            return ($b['trade_count']<=>$a['trade_count'])
                ?:strcasecmp($a['player_a_name'],$b['player_a_name'])
                ?:strcasecmp($a['player_b_name'],$b['player_b_name']);
        });

        $needByCard=[];
        $extraByCard=[];
        $reachableByCard=[];
        foreach($cards as $cid=>$_){
            $needByCard[$cid]=0;
            $extraByCard[$cid]=0;
            $reachableByCard[$cid]=0;
        }

        foreach($needs as $pid=>$rows){
            foreach($rows as $cid=>$qty){
                $needByCard[$cid]+=$qty;
                if(isset($reachableNeed[$pid][$cid])){
                    $reachableByCard[$cid]+=$qty;
                }
            }
        }
        foreach($extras as $rows){
            foreach($rows as $cid=>$qty){
                $extraByCard[$cid]+=$qty;
            }
        }

        $scarcity=[];
        foreach($cards as $cid=>$card){
            $needed=(int)$needByCard[$cid];
            if($needed<=0) continue;
            $reachable=min($needed,(int)$reachableByCard[$cid]);
            $scarcity[]=[
                'card_id'=>$cid,
                'card_name'=>$card['name'],
                'category'=>$card['category'],
                'needed'=>$needed,
                'available_extras'=>(int)$extraByCard[$cid],
                'satisfiable'=>$reachable,
                'unmet'=>max($needed-$reachable,0),
            ];
        }
        usort($scarcity,static function(array $a,array $b):int{
            return ($b['unmet']<=>$a['unmet'])
                ?:($b['needed']<=>$a['needed'])
                ?:strcasecmp($a['card_name'],$b['card_name']);
        });

        $playersWithNeed=[];
        foreach($needs as $pid=>$rows){
            if(array_sum($rows)>0) $playersWithNeed[$pid]=true;
        }

        $reachableUnits=array_sum($reachableByCard);

        return [
            'optimizer_version'=>'V8.42',
            'optimizer_mode'=>'directed-requester-benefit',
            'card_count'=>$cardCount,
            'eligible_players'=>array_values($eligible),
            'excluded_players'=>$excluded,
            'eligible_player_count'=>count($eligible),
            'excluded_player_count'=>count($excluded),
            'players_with_need'=>count($playersWithNeed),
            'players_helped'=>count($requestersWithOpportunity),
            'total_need_units'=>$totalNeed,
            'fulfilled_units'=>$reachableUnits,
            'fulfillment_pct'=>$totalNeed>0?($reachableUnits/$totalNeed)*100:100.0,
            'trade_count'=>count($opportunities),
            'trade_units'=>$opportunities,
            'opportunities'=>$opportunities,
            'transfer_line_count'=>count($opportunities),
            'relationship_count'=>count($relationships),
            'reciprocal_relationship_count'=>count(array_filter(
                $relationships,
                static fn(array $r):bool=>(bool)$r['reciprocal']
            )),
            'transfers'=>array_merge(
                ...array_map(
                    static fn(array $r):array=>$r['transfers'],
                    array_values($relationships)
                )
            )?:[],
            'relationships'=>array_values($relationships),
            'scarcity'=>$scarcity,
            'unmet_units'=>max($totalNeed-$reachableUnits,0),
        ];
    }

    private function pairKey(int $a,int $b):string
    {
        return min($a,$b).':'.max($a,$b);
    }

    private function emptyResult(int $cardCount,array $eligible,array $excluded):array
    {
        return [
            'optimizer_version'=>'V8.42',
            'optimizer_mode'=>'directed-requester-benefit',
            'card_count'=>$cardCount,
            'eligible_players'=>array_values($eligible),
            'excluded_players'=>$excluded,
            'eligible_player_count'=>count($eligible),
            'excluded_player_count'=>count($excluded),
            'players_with_need'=>0,'players_helped'=>0,'total_need_units'=>0,
            'fulfilled_units'=>0,'fulfillment_pct'=>100.0,'trade_count'=>0,
            'trade_units'=>[],'opportunities'=>[],'transfer_line_count'=>0,
            'relationship_count'=>0,'reciprocal_relationship_count'=>0,
            'transfers'=>[],'relationships'=>[],'scarcity'=>[],'unmet_units'=>0,
        ];
    }
}
