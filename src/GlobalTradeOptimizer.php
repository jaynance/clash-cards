<?php
declare(strict_types=1);

/**
 * V8.41 All-Possible-Trades graph builder.
 *
 * This deliberately does NOT allocate or consume extras across the clan.
 * A relationship exists whenever:
 *   - A has an extra card B needs,
 *   - B has an extra card A needs, and
 *   - the two cards belong to the same Clash card group.
 *
 * Every legal give/receive combination is returned. This is a discovery graph,
 * not a globally optimized execution plan; players decide which trades happen.
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

        $eligiblePlayers=[];
        $excludedPlayers=[];
        foreach($playerRows as $row){
            $player=[
                'id'=>(int)$row['id'],
                'name'=>(string)$row['display_name'],
                'saved_card_rows'=>(int)$row['saved_card_rows'],
            ];
            if($cardCount>0 && $player['saved_card_rows']===$cardCount){
                $eligiblePlayers[$player['id']]=$player;
            }else{
                $excludedPlayers[]=$player;
            }
        }

        if(!$eligiblePlayers || $cardCount===0){
            return $this->emptyResult($cardCount,$eligiblePlayers,$excludedPlayers);
        }

        $ids=array_keys($eligiblePlayers);
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$this->pdo->prepare(
            "SELECT p.id AS player_id,c.id AS card_id,c.name AS card_name,
                    c.category,c.required_qty,pc.owned_qty
             FROM players p
             JOIN player_cards pc ON pc.player_id=p.id
             JOIN cards c ON c.id=pc.card_id
             WHERE p.id IN ($placeholders)
             ORDER BY c.id,p.id"
        );
        $stmt->execute($ids);

        $cards=[];
        $needs=[];
        $surpluses=[];
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
                'required_qty'=>$required,
            ];
            if($owned<$required){
                $qty=$required-$owned;
                $needs[$pid][$cid]=$qty;
                $totalNeed+=$qty;
            }elseif($owned>$required){
                $surpluses[$pid][$cid]=$owned-$required;
            }
        }

        $relationships=[];
        $tradeUnits=[];
        $playersWithPossibility=[];
        $ids=array_keys($eligiblePlayers);

        for($i=0;$i<count($ids);$i++){
            for($j=$i+1;$j<count($ids);$j++){
                $a=(int)$ids[$i];
                $b=(int)$ids[$j];

                $aToB=$this->possibleLegs($a,$b,$cards,$needs,$surpluses);
                $bToA=$this->possibleLegs($b,$a,$cards,$needs,$surpluses);
                if(!$aToB || !$bToA) continue;

                $groups=[];
                foreach($aToB as $legA){
                    foreach($bToA as $legB){
                        if($legA['category']!==$legB['category']) continue;

                        $category=$legA['category'];
                        $trade=[
                            'trade_no'=>count($tradeUnits)+1,
                            'category'=>$category,
                            'player_a_id'=>$a,
                            'player_a_name'=>$eligiblePlayers[$a]['name'],
                            'player_b_id'=>$b,
                            'player_b_name'=>$eligiblePlayers[$b]['name'],
                            'player_a_gives_card_id'=>$legA['card_id'],
                            'player_a_gives_card_name'=>$cards[$legA['card_id']]['name'],
                            'player_b_gives_card_id'=>$legB['card_id'],
                            'player_b_gives_card_name'=>$cards[$legB['card_id']]['name'],
                            'qty_each'=>1,
                        ];
                        $tradeUnits[]=$trade;
                        $groups[$category]['category']=$category;
                        $groups[$category]['trades'][]=$trade;
                    }
                }

                if(!$groups) continue;

                $pairKey=$this->pairKey($a,$b);
                $transfers=[];
                foreach($aToB as $leg){
                    if(!isset($groups[$leg['category']])) continue;
                    $transfers[]=[
                        'from_player_id'=>$a,
                        'from_player_name'=>$eligiblePlayers[$a]['name'],
                        'to_player_id'=>$b,
                        'to_player_name'=>$eligiblePlayers[$b]['name'],
                        'card_id'=>$leg['card_id'],
                        'card_name'=>$cards[$leg['card_id']]['name'],
                        'category'=>$leg['category'],
                        'qty'=>$leg['qty'],
                    ];
                }
                foreach($bToA as $leg){
                    if(!isset($groups[$leg['category']])) continue;
                    $transfers[]=[
                        'from_player_id'=>$b,
                        'from_player_name'=>$eligiblePlayers[$b]['name'],
                        'to_player_id'=>$a,
                        'to_player_name'=>$eligiblePlayers[$a]['name'],
                        'card_id'=>$leg['card_id'],
                        'card_name'=>$cards[$leg['card_id']]['name'],
                        'category'=>$leg['category'],
                        'qty'=>$leg['qty'],
                    ];
                }

                $tradeCount=0;
                foreach($groups as &$group){
                    $group['trade_count']=count($group['trades']);
                    $group['unit_count']=$group['trade_count']*2;
                    $group['transfers']=array_values(array_filter(
                        $transfers,
                        static fn(array $t):bool=>$t['category']===$group['category']
                    ));
                    $tradeCount+=$group['trade_count'];
                }
                unset($group);

                $relationships[$pairKey]=[
                    'pair_key'=>$pairKey,
                    'player_a_id'=>$a,
                    'player_a_name'=>$eligiblePlayers[$a]['name'],
                    'player_b_id'=>$b,
                    'player_b_name'=>$eligiblePlayers[$b]['name'],
                    'transfers'=>$transfers,
                    'trade_groups'=>array_values($groups),
                    'trade_count'=>$tradeCount,
                    'unit_count'=>array_sum(array_map(static fn(array $t):int=>(int)$t['qty'],$transfers)),
                    'reciprocal'=>true,
                ];
                $playersWithPossibility[$a]=true;
                $playersWithPossibility[$b]=true;
            }
        }

        uasort($relationships,static function(array $a,array $b):int{
            return ($b['trade_count']<=>$a['trade_count'])
                ?:strcasecmp($a['player_a_name'],$b['player_a_name'])
                ?:strcasecmp($a['player_b_name'],$b['player_b_name']);
        });

        $needByCard=[];
        $supplyByCard=[];
        $reachableByCard=[];
        foreach($cards as $cid=>$_){
            $needByCard[$cid]=0;
            $supplyByCard[$cid]=0;
            $reachableByCard[$cid]=0;
        }
        foreach($needs as $playerNeeds){
            foreach($playerNeeds as $cid=>$qty) $needByCard[$cid]+=$qty;
        }
        foreach($surpluses as $playerExtras){
            foreach($playerExtras as $cid=>$qty) $supplyByCard[$cid]+=$qty;
        }

        // A needed unit is "reachable" if at least one reciprocal same-group
        // relationship can supply that card. This is intentionally not an
        // allocation: the same extra may appear as an option for multiple players.
        foreach($relationships as $relationship){
            foreach($relationship['transfers'] as $t){
                $cid=(int)$t['card_id'];
                $to=(int)$t['to_player_id'];
                $reachableByCard[$cid]+=min(
                    (int)$t['qty'],
                    (int)($needs[$to][$cid]??0)
                );
            }
        }
        foreach($reachableByCard as $cid=>$qty){
            $reachableByCard[$cid]=min($qty,$needByCard[$cid]);
        }

        $scarcity=[];
        foreach($cards as $cid=>$card){
            $need=(int)$needByCard[$cid];
            if($need<=0) continue;
            $reachable=(int)$reachableByCard[$cid];
            $scarcity[]=[
                'card_id'=>$cid,
                'card_name'=>$card['name'],
                'category'=>$card['category'],
                'needed'=>$need,
                'available_extras'=>(int)$supplyByCard[$cid],
                'satisfiable'=>$reachable,
                'unmet'=>max($need-$reachable,0),
            ];
        }
        usort($scarcity,static function(array $a,array $b):int{
            return ($b['unmet']<=>$a['unmet'])
                ?:($b['needed']<=>$a['needed'])
                ?:strcasecmp($a['card_name'],$b['card_name']);
        });

        $playersWithNeed=[];
        foreach($needs as $pid=>$playerNeeds){
            if(array_sum($playerNeeds)>0) $playersWithNeed[$pid]=true;
        }

        $reachableUnits=array_sum($reachableByCard);

        return [
            'optimizer_version'=>'V8.41',
            'optimizer_mode'=>'all-possible-group-constrained-bilateral',
            'card_count'=>$cardCount,
            'eligible_players'=>array_values($eligiblePlayers),
            'excluded_players'=>$excludedPlayers,
            'eligible_player_count'=>count($eligiblePlayers),
            'excluded_player_count'=>count($excludedPlayers),
            'players_with_need'=>count($playersWithNeed),
            'players_helped'=>count($playersWithPossibility),
            'total_need_units'=>$totalNeed,
            'fulfilled_units'=>$reachableUnits,
            'fulfillment_pct'=>$totalNeed>0?($reachableUnits/$totalNeed)*100:100.0,
            'trade_count'=>count($tradeUnits),
            'trade_units'=>$tradeUnits,
            'transfer_line_count'=>array_sum(array_map(static fn(array $r):int=>count($r['transfers']),$relationships)),
            'relationship_count'=>count($relationships),
            'reciprocal_relationship_count'=>count($relationships),
            'transfers'=>array_merge(...array_map(static fn(array $r):array=>$r['transfers'],array_values($relationships))) ?: [],
            'relationships'=>array_values($relationships),
            'scarcity'=>$scarcity,
            'unmet_units'=>max($totalNeed-$reachableUnits,0),
        ];
    }

    private function possibleLegs(
        int $fromPlayer,int $toPlayer,array $cards,array $needs,array $surpluses
    ):array{
        $legs=[];
        foreach($surpluses[$fromPlayer]??[] as $cardId=>$available){
            $needed=(int)($needs[$toPlayer][$cardId]??0);
            if($available<=0 || $needed<=0) continue;
            $legs[]=[
                'card_id'=>(int)$cardId,
                'category'=>(string)$cards[$cardId]['category'],
                'qty'=>min((int)$available,$needed),
            ];
        }
        return $legs;
    }

    private function pairKey(int $a,int $b):string
    {
        return min($a,$b).':'.max($a,$b);
    }

    private function emptyResult(int $cardCount,array $eligiblePlayers,array $excludedPlayers):array
    {
        return [
            'optimizer_version'=>'V8.41',
            'optimizer_mode'=>'all-possible-group-constrained-bilateral',
            'card_count'=>$cardCount,
            'eligible_players'=>array_values($eligiblePlayers),
            'excluded_players'=>$excludedPlayers,
            'eligible_player_count'=>count($eligiblePlayers),
            'excluded_player_count'=>count($excludedPlayers),
            'players_with_need'=>0,'players_helped'=>0,'total_need_units'=>0,
            'fulfilled_units'=>0,'fulfillment_pct'=>100.0,'trade_count'=>0,
            'trade_units'=>[],'transfer_line_count'=>0,'relationship_count'=>0,
            'reciprocal_relationship_count'=>0,'transfers'=>[],'relationships'=>[],
            'scarcity'=>[],'unmet_units'=>0,
        ];
    }
}
