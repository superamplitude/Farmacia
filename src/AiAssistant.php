<?php
declare(strict_types=1);

final class AiAssistant
{
    public static function medicationHits(PDO $db,string $question,int $limit=5):array{
        $words=preg_split('/[^\pL\pN]+/u',mb_strtolower($question));$words=array_values(array_filter($words,fn($w)=>mb_strlen($w)>=4));$hits=[];$seen=[];
        foreach(array_slice($words,0,8) as $w){foreach(Catalog::search($db,$w,$limit) as $m){if(!isset($seen[$m['id']])){$seen[$m['id']]=1;$hits[]=$m;if(count($hits)>=$limit)break 2;}}}return $hits;
    }
    public static function answer(PDO $db,string $question,array $hits):string{
        $fallback=self::fallback($question,$hits); if((string)env('AI_ENABLED','0')!=='1')return $fallback;
        $url=trim((string)env('AI_API_URL',''));$key=trim((string)env('AI_API_KEY',''));$model=trim((string)env('AI_MODEL',''));if($url===''||$key===''||$model==='')return $fallback;
        $context=[];foreach($hits as $m)$context[]=['nome'=>$m['product_name'],'principio_ativo'=>$m['active_ingredient'],'empresa'=>$m['company'],'categoria'=>$m['regulatory_category'],'classe'=>$m['therapeutic_class'],'apresentacao'=>$m['presentation'],'exige_receita'=>(bool)$m['requires_prescription'],'controlado'=>(bool)$m['controlled']];
        $system='Você é o assistente informativo de uma farmácia brasileira. Responda em português claro, com base no contexto fornecido e sem inventar. Não diagnostique, não prescreva, não recomende iniciar, interromper ou alterar medicamento, não defina dose individual e não substitua médico ou farmacêutico. Para dose, contraindicação, interação, gravidez, criança, idoso, alergia, reação adversa importante ou combinação de remédios, explique de forma geral e oriente validação com farmacêutico/médico e consulta à bula oficial. Em sinais de emergência, oriente atendimento urgente. Diferencie informação do catálogo de orientação clínica.';
        $payload=['model'=>$model,'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>'Contexto do catálogo: '.json_encode($context,JSON_UNESCAPED_UNICODE).'\nPergunta: '.$question]],'temperature'=>0.2,'max_tokens'=>500];
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>(int)env('AI_TIMEOUT','25')]);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($code<200||$code>=300||!$raw)return $fallback;$data=json_decode($raw,true);return trim((string)($data['choices'][0]['message']['content']??$fallback));
    }
    private static function fallback(string $question,array $hits):string{
        if(!$hits)return 'Não encontrei um medicamento correspondente no catálogo. Posso pesquisar por nome comercial, princípio ativo, laboratório ou registro. Para dúvidas clínicas, confirme sempre com o farmacêutico.';
        $names=array_map(fn($m)=>$m['product_name'],$hits);return 'Encontrei no catálogo: '.implode(', ',$names).'. Posso mostrar detalhes cadastrais e a bula oficial. Para dose, interação, contraindicação ou escolha de tratamento, a resposta precisa ser validada pelo farmacêutico ou médico.';
    }
}
