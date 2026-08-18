<?php
declare(strict_types=1);

/**
 * V8.35 Group-Constrained Trade Optimizer
 *
 * Only executable Clash trades count:
 *  - A has an extra card B needs.
 *  - B has an extra card A needs.
 *  - Both cards are in the SAME card category/group.
 *
 * One loop iteration creates one legal bilateral trade unit (1 card each way).
 * Cross-group and one-way transfers never appear in the returned plan.
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

            if($cardCount>0 && (int)$row['saved_card_rows']===$cardCount){
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
            "SELECT p.id AS player_id,p.display_name AS player_name,
                    c.id AS card_id,c.name AS card_name,c.category,c.required_qty,pc.owned_qty
             FROM players p
             JOIN player_cards pc ON pc.player_id=p.id
             JOIN cards c ON c.id=pc.card_id
             WHERE p.id IN ($placeholders)
             ORDER BY c.id,p.id"
        );
        $stmt->execute($ids);
        $rows=$stmt->fetchAll();

        $cards=[];
        $needs=[];       // [playerId][cardId] => qty
        $surpluses=[];   // [playerId][cardId] => qty
        $totalNeed=0;

        foreach($rows as $row){
            $playerId=(int)$row['player_id'];
            $cardId=(int)$row['card_id'];
            $required=(int)$row['required_qty'];
            $owned=(int)$row['owned_qty'];

            $cards[$cardId]=[
                'id'=>$cardId,
                'name'=>(string)$row['card_name'],
                'category'=>(string)$row['category'],
                'required_qty'=>$required,
            ];

            if($owned<$required){
                $qty=$required-$owned;
                $needs[$playerId][$cardId]=$qty;
                $totalNeed+=$qty;
            }elseif($owned>$required){
                $surpluses[$playerId][$cardId]=$owned-$required;
            }
        }

        $needRemaining=$needs;
        $surplusRemaining=$surpluses;
        $helpedPlayers=[];
        $pairUsage=[];
        $pairCategoryUsage=[];
        $tradeUnits=[];

        while(true){
            $candidates=$this->buildTradeCandidates(
                $eligiblePlayers,$cards,$needRemaining,$surplusRemaining,
                $helpedPlayers,$pairUsage,$pairCategoryUsage
            );

            if(!$candidates){
                break;
            }

            usort($candidates,static function(array $a,array $b):int{
                return ($b['new_help_count']<=>$a['new_help_count'])
                    ?:($b['existing_pair_category']<=>$a['existing_pair_category'])
                    ?:($b['existing_pair']<=>$a['existing_pair'])
                    ?:($a['flexibility']<=>$b['flexibility'])
                    ?:strcmp($a['category'],$b['category'])
                    ?:($a['player_a_id']<=>$b['player_a_id'])
                    ?:($a['player_b_id']<=>$b['player_b_id'])
                    ?:($a['a_gives_card_id']<=>$b['a_gives_card_id'])
                    ?:($a['b_gives_card_id']<=>$b['b_gives_card_id']);
            });

            $trade=$candidates[0];
            $a=(int)$trade['player_a_id'];
            $b=(int)$trade['player_b_id'];
            $aGives=(int)$trade['a_gives_card_id'];
            $bGives=(int)$trade['b_gives_card_id'];

            $surplusRemaining[$a][$aGives]--;
            $needRemaining[$b][$aGives]--;
            $surplusRemaining[$b][$bGives]--;
            $needRemaining[$a][$bGives]--;

            $this->cleanupZero($surplusRemaining,$a,$aGives);
            $this->cleanupZero($surplusRemaining,$b,$bGives);
            $this->cleanupZero($needRemaining,$b,$aGives);
            $this->cleanupZero($needRemaining,$a,$bGives);

            $helpedPlayers[$a]=true;
            $helpedPlayers[$b]=true;

            $pairKey=$this->pairKey($a,$b);
            $pairCategoryKey=$pairKey.'|'.$trade['category'];
            $pairUsage[$pairKey]=true;
            $pairCategoryUsage[$pairCategoryKey]=true;

            $tradeUnits[]=[
                'trade_no'=>count($tradeUnits)+1,
                'category'=>$trade['category'],
                'player_a_id'=>$a,
                'player_a_name'=>$eligiblePlayers[$a]['name'],
                'player_b_id'=>$b,
                'player_b_name'=>$eligiblePlayers[$b]['name'],
                'player_a_gives_card_id'=>$aGives,
                'player_a_gives_card_name'=>$cards[$aGives]['name'],
                'player_b_gives_card_id'=>$bGives,
                'player_b_gives_card_name'=>$cards[$bGives]['name'],
                'qty_each'=>1,
            ];
        }

        $transfers=[];
        foreach($tradeUnits as $trade){
            $transfers[]=[
                'from_player_id'=>$trade['player_a_id'],
                'from_player_name'=>$trade['player_a_name'],
                'to_player_id'=>$trade['player_b_id'],
                'to_player_name'=>$trade['player_b_name'],
                'card_id'=>$trade['player_a_gives_card_id'],
                'card_name'=>$trade['player_a_gives_card_name'],
                'category'=>$trade['category'],
                'qty'=>1,
            ];
            $transfers[]=[
                'from_player_id'=>$trade['player_b_id'],
                'from_player_name'=>$trade['player_b_name'],
                'to_player_id'=>$trade['player_a_id'],
                'to_player_name'=>$trade['player_a_name'],
                'card_id'=>$trade['player_b_gives_card_id'],
                'card_name'=>$trade['player_b_gives_card_name'],
                'category'=>$trade['category'],
                'qty'=>1,
            ];
        }

        $aggregated=[];
        foreach($transfers as $transfer){
            $key=$transfer['from_player_id'].':'.$transfer['to_player_id'].':'.
                $transfer['category'].':'.$transfer['card_id'];
            if(!isset($aggregated[$key])){
                $aggregated[$key]=$transfer;
            }else{
                $aggregated[$key]['qty']+=$transfer['qty'];
            }
        }
        $transfers=array_values($aggregated);

        usort($transfers,static function(array $a,array $b):int{
            return strcasecmp($a['from_player_name'],$b['from_player_name'])
                ?:strcasecmp($a['to_player_name'],$b['to_player_name'])
                ?:strcasecmp($a['category'],$b['category'])
                ?:strcasecmp($a['card_name'],$b['card_name']);
        });

        $relationships=[];
        foreach($tradeUnits as $trade){
            $pairKey=$this->pairKey((int)$trade['player_a_id'],(int)$trade['player_b_id']);

            if(!isset($relationships[$pairKey])){
                $aId=min((int)$trade['player_a_id'],(int)$trade['player_b_id']);
                $bId=max((int)$trade['player_a_id'],(int)$trade['player_b_id']);
                $relationships[$pairKey]=[
                    'pair_key'=>$pairKey,
                    'player_a_id'=>$aId,
                    'player_a_name'=>$eligiblePlayers[$aId]['name'],
                    'player_b_id'=>$bId,
                    'player_b_name'=>$eligiblePlayers[$bId]['name'],
                    'transfers'=>[],
                    'trade_groups'=>[],
                    'trade_count'=>0,
                    'unit_count'=>0,
                    'reciprocal'=>true,
                ];
            }

            $category=(string)$trade['category'];
            if(!isset($relationships[$pairKey]['trade_groups'][$category])){
                $relationships[$pairKey]['trade_groups'][$category]=[
                    'category'=>$category,
                    'trades'=>[],
                    'trade_count'=>0,
                    'unit_count'=>0,
                ];
            }

            $relationships[$pairKey]['trade_groups'][$category]['trades'][]=$trade;
            $relationships[$pairKey]['trade_groups'][$category]['trade_count']++;
            $relationships[$pairKey]['trade_groups'][$category]['unit_count']+=2;
            $relationships[$pairKey]['trade_count']++;
            $relationships[$pairKey]['unit_count']+=2;
        }

        foreach($transfers as $transfer){
            $pairKey=$this->pairKey((int)$transfer['from_player_id'],(int)$transfer['to_player_id']);
            if(isset($relationships[$pairKey])){
                $relationships[$pairKey]['transfers'][]=$transfer;
            }
        }

        foreach($relationships as &$relationship){
            foreach($relationship['trade_groups'] as $category=>&$group){
                $group['transfers']=array_values(array_filter(
                    $relationship['transfers'],
                    static fn(array $t):bool=>$t['category']===$category
                ));
            }
            unset($group);
            $relationship['trade_groups']=array_values($relationship['trade_groups']);
        }
        unset($relationship);

        uasort($relationships,static function(array $a,array $b):int{
            return ($b['trade_count']<=>$a['trade_count'])
                ?:strcasecmp($a['player_a_name'],$b['player_a_name'])
                ?:strcasecmp($a['player_b_name'],$b['player_b_name']);
        });

        $needByCard=[];
        $supplyByCard=[];
        $fulfilledByCard=[];
        foreach($cards as $cardId=>$_card){
            $needByCard[$cardId]=0;
            $supplyByCard[$cardId]=0;
            $fulfilledByCard[$cardId]=0;
        }
        foreach($needs as $playerNeeds){
            foreach($playerNeeds as $cardId=>$qty){
                $needByCard[$cardId]+=$qty;
            }
        }
        foreach($surpluses as $playerSurplus){
            foreach($playerSurplus as $cardId=>$qty){
                $supplyByCard[$cardId]+=$qty;
            }
        }
        foreach($transfers as $transfer){
            $fulfilledByCard[(int)$transfer['card_id']]+=(int)$transfer['qty'];
        }

        $scarcity=[];
        foreach($cards as $cardId=>$card){
            $need=(int)$needByCard[$cardId];
            if($need<=0) continue;

            $supply=(int)$supplyByCard[$cardId];
            $fulfilled=(int)$fulfilledByCard[$cardId];

            $scarcity[]=[
                'card_id'=>$cardId,
                'card_name'=>$card['name'],
                'category'=>$card['category'],
                'needed'=>$need,
                'available_extras'=>$supply,
                'satisfiable'=>$fulfilled,
                'unmet'=>max($need-$fulfilled,0),
            ];
        }

        usort($scarcity,static function(array $a,array $b):int{
            return ($b['unmet']<=>$a['unmet'])
                ?:($b['needed']<=>$a['needed'])
                ?:strcasecmp($a['card_name'],$b['card_name']);
        });

        $playersWithNeed=[];
        foreach($needs as $playerId=>$playerNeeds){
            if(array_sum($playerNeeds)>0){
                $playersWithNeed[$playerId]=true;
            }
        }

        $fulfilledUnits=count($tradeUnits)*2;

        return [
            'optimizer_version'=>'V8.35',
            'optimizer_mode'=>'group-constrained-bilateral',
            'card_count'=>$cardCount,
            'eligible_players'=>array_values($eligiblePlayers),
            'excluded_players'=>$excludedPlayers,
            'eligible_player_count'=>count($eligiblePlayers),
            'excluded_player_count'=>count($excludedPlayers),
            'players_with_need'=>count($playersWithNeed),
            'players_helped'=>count($helpedPlayers),
            'total_need_units'=>$totalNeed,
            'fulfilled_units'=>$fulfilledUnits,
            'fulfillment_pct'=>$totalNeed>0?($fulfilledUnits/$totalNeed)*100:100.0,
            'trade_count'=>count($tradeUnits),
            'trade_units'=>$tradeUnits,
            'transfer_line_count'=>count($transfers),
            'relationship_count'=>count($relationships),
            'reciprocal_relationship_count'=>count($relationships),
            'transfers'=>$transfers,
            'relationships'=>array_values($relationships),
            'scarcity'=>$scarcity,
            'unmet_units'=>max($totalNeed-$fulfilledUnits,0),
        ];
    }

    private function buildTradeCandidates(
        array $players,array $cards,array $needs,array $surpluses,
        array $helpedPlayers,array $pairUsage,array $pairCategoryUsage
    ):array{
        $ids=array_keys($players);
        $candidates=[];

        for($i=0;$i<count($ids);$i++){
            for($j=$i+1;$j<count($ids);$j++){
                $a=(int)$ids[$i];
                $b=(int)$ids[$j];

                $aToB=$this->possibleLegs($a,$b,$cards,$needs,$surpluses);
                $bToA=$this->possibleLegs($b,$a,$cards,$needs,$surpluses);
                if(!$aToB || !$bToA) continue;

                foreach($aToB as $legA){
                    foreach($bToA as $legB){
                        if($legA['category']!==$legB['category']) continue;

                        $pairKey=$this->pairKey($a,$b);
                        $pairCategoryKey=$pairKey.'|'.$legA['category'];

                        $candidates[]=[
                            'player_a_id'=>$a,
                            'player_b_id'=>$b,
                            'category'=>$legA['category'],
                            'a_gives_card_id'=>$legA['card_id'],
                            'b_gives_card_id'=>$legB['card_id'],
                            'new_help_count'=>(isset($helpedPlayers[$a])?0:1)+(isset($helpedPlayers[$b])?0:1),
                            'existing_pair'=>isset($pairUsage[$pairKey]),
                            'existing_pair_category'=>isset($pairCategoryUsage[$pairCategoryKey]),
                            'flexibility'=>$legA['flexibility']+$legB['flexibility'],
                        ];
                    }
                }
            }
        }

        return $candidates;
    }

    private function possibleLegs(
        int $fromPlayer,int $toPlayer,array $cards,array $needs,array $surpluses
    ):array{
        $legs=[];

        foreach($surpluses[$fromPlayer]??[] as $cardId=>$available){
            if($available<=0 || (int)($needs[$toPlayer][$cardId]??0)<=0) continue;

            $flexibility=0;
            foreach($needs as $otherPlayerId=>$playerNeeds){
                if($otherPlayerId===$fromPlayer) continue;
                if((int)($playerNeeds[$cardId]??0)>0) $flexibility++;
            }

            $legs[]=[
                'card_id'=>(int)$cardId,
                'category'=>$cards[$cardId]['category'],
                'flexibility'=>$flexibility,
            ];
        }

        return $legs;
    }

    private function cleanupZero(array &$matrix,int $playerId,int $cardId):void
    {
        if(($matrix[$playerId][$cardId]??0)<=0){
            unset($matrix[$playerId][$cardId]);
        }
        if(isset($matrix[$playerId]) && !$matrix[$playerId]){
            unset($matrix[$playerId]);
        }
    }

    private function pairKey(int $a,int $b):string
    {
        return min($a,$b).':'.max($a,$b);
    }

    private function emptyResult(int $cardCount,array $eligiblePlayers,array $excludedPlayers):array
    {
        return [
            'optimizer_version'=>'V8.35',
            'optimizer_mode'=>'group-constrained-bilateral',
            'card_count'=>$cardCount,
            'eligible_players'=>array_values($eligiblePlayers),
            'excluded_players'=>$excludedPlayers,
            'eligible_player_count'=>count($eligiblePlayers),
            'excluded_player_count'=>count($excludedPlayers),
            'players_with_need'=>0,
            'players_helped'=>0,
            'total_need_units'=>0,
            'fulfilled_units'=>0,
            'fulfillment_pct'=>100.0,
            'trade_count'=>0,
            'trade_units'=>[],
            'transfer_line_count'=>0,
            'relationship_count'=>0,
            'reciprocal_relationship_count'=>0,
            'transfers'=>[],
            'relationships'=>[],
            'scarcity'=>[],
            'unmet_units'=>0,
        ];
    }
}
