<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/GlobalTradeOptimizer.php';

function failTest(string $message): never {
    fwrite(STDERR,"FAIL: {$message}\n");
    exit(1);
}
function ok(bool $condition,string $message): void {
    if(!$condition) failTest($message);
}

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$pdo->exec('
CREATE TABLE players(id INTEGER PRIMARY KEY,display_name TEXT NOT NULL);
CREATE TABLE cards(id INTEGER PRIMARY KEY,name TEXT NOT NULL,category TEXT NOT NULL,required_qty INTEGER NOT NULL);
CREATE TABLE player_cards(player_id INTEGER NOT NULL,card_id INTEGER NOT NULL,owned_qty INTEGER NOT NULL,PRIMARY KEY(player_id,card_id));
');

foreach([1=>'Requester',2=>'Donor',3=>'WrongGroup'] as $id=>$name){
    $s=$pdo->prepare('INSERT INTO players(id,display_name) VALUES(?,?)');
    $s->execute([$id,$name]);
}

// Two Elixir cards and two Super cards.
$cards=[
    [1,'Barbarian','Elixir',1],
    [2,'Archer','Elixir',1],
    [3,'Super A','Super',1],
    [4,'Super B','Super',1],
];
foreach($cards as $row){
    $s=$pdo->prepare('INSERT INTO cards(id,name,category,required_qty) VALUES(?,?,?,?)');
    $s->execute($row);
}

/*
Requester needs Barbarian and has extra Archer.
Donor has extra Barbarian, but DOES NOT need Archer.
=> This MUST be offered to Requester.

WrongGroup has extra Barbarian too, but Requester would still use Archer (Elixir),
so it is also a valid donor. We separately verify no cross-group offer can appear.
*/
$inventory=[
    1=>[1=>0,2=>2,3=>1,4=>1],
    2=>[1=>2,2=>1,3=>1,4=>1],
    3=>[1=>2,2=>1,3=>2,4=>0],
];
foreach($inventory as $pid=>$rows){
    foreach($rows as $cid=>$qty){
        $s=$pdo->prepare('INSERT INTO player_cards(player_id,card_id,owned_qty) VALUES(?,?,?)');
        $s->execute([$pid,$cid,$qty]);
    }
}

$result=(new GlobalTradeOptimizer($pdo))->optimize();

ok(
    ($result['optimizer_mode']??'')==='directed-requester-benefit',
    'V8.42 directed mode must be active'
);

$requesterOpps=array_values(array_filter(
    $result['opportunities']??[],
    static fn(array $o):bool=>(int)$o['requester_id']===1
));

ok(count($requesterOpps)===2,'Requester should see both players who can supply Barbarian');

foreach($requesterOpps as $opp){
    ok((int)$opp['receive_card_id']===1,'requested card must be Barbarian');
    ok($opp['category']==='Elixir','trade category must be Elixir');
    ok(count($opp['offer_choices'])===1,'Requester should have one Elixir offer choice');
    ok((int)$opp['offer_choices'][0]['card_id']===2,'Requester should offer Archer');
    ok($opp['offer_choices'][0]['category']===$opp['category'],'offer and request must be same group');
}

// Donor has no needs, so Donor should not be bothered with a requester-facing opportunity.
$donorRequests=array_filter(
    $result['opportunities']??[],
    static fn(array $o):bool=>(int)$o['requester_id']===2
);
ok(count($donorRequests)===0,'Donor with no needs should have no player-facing requests');

foreach($result['opportunities']??[] as $opp){
    foreach($opp['offer_choices']??[] as $offer){
        ok(
            (string)$offer['category']===(string)$opp['category'],
            'all offer choices must remain in the requested card group'
        );
    }
}

fwrite(STDOUT,"PASS: V8.42 exposes requester-benefit trades without requiring donor need.\n");
