<?php
/**
 * Navegação por etapas do Galera Tech, sem JavaScript.
 * O config.php inicia a sessão e fornece token(), verify_token(), digits(), cpf_ok(), e().
 * Mantém dados temporários apenas na sessão; o banco só é alterado na confirmação.
 */

$etapa = max(1, min(5, (int) ($_SESSION['gt_etapa'] ?? 1)));
$_SESSION['gt_dados'] ??= [];
$_SESSION['gt_concluidas'] ??= [];
$dadosEtapas = &$_SESSION['gt_dados'];
$erroEtapa = '';

$camposPorEtapa = [
    1 => ['nome', 'data_nascimento', 'cpf', 'rg', 'sexo', 'email', 'telefone'],
    2 => ['reside_rio_preto', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado'],
    3 => ['nome_escola', 'serie_escolar', 'origem_divulgacao'],
    4 => ['responsavel_nome', 'parentesco', 'responsavel_telefone'],
];

function gtIdade(string $data): ?int {
    $nascimento = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    $hoje = new DateTimeImmutable('today');
    if (!$nascimento || $nascimento->format('Y-m-d') !== $data || $nascimento > $hoje) return null;
    return $nascimento->diff($hoje)->y;
}

function gtValidarEtapa(int $passo, array $d): string {
    $exigir = static function (array $campos) use ($d): bool {
        foreach ($campos as $campo) if (trim((string) ($d[$campo] ?? '')) === '') return false;
        return true;
    };
    $tamanho = static fn (string $key): int => (function_exists('mb_strlen') ? mb_strlen(trim((string)($d[$key] ?? '')), 'UTF-8') : strlen(trim((string)($d[$key] ?? ''))));
    if ($passo === 1) {
        if (!$exigir(['nome','data_nascimento','cpf','rg','sexo','email','telefone']))
            return 'Preencha todos os campos obrigatórios de dados pessoais.';
        $idade = gtIdade((string)$d['data_nascimento']);
        if ($tamanho('nome') < 3 || $tamanho('nome') > 150 || $idade === null || $idade > 120)
            return 'Confira o nome completo e a data de nascimento.';
        if (!cpf_ok(digits((string)$d['cpf']))) return 'Informe um CPF válido.';
        if ($tamanho('rg') > 30) return 'Documento de identidade muito longo.';
        if (!in_array($d['sexo'], ['Masculino','Feminino','Não desejo informar'], true)) return 'Selecione o sexo.';
        if ($tamanho('email') > 150 || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) return 'Informe um e-mail válido.';
        if (strlen(digits((string)$d['telefone'])) !== 11) return 'Informe um WhatsApp com DDD e 11 dígitos.';
    } elseif ($passo === 2) {
        if (!$exigir(['reside_rio_preto','cep','logradouro','numero','bairro','cidade','estado']))
            return 'Preencha os campos obrigatórios do endereço.';
        if (!in_array($d['reside_rio_preto'], ['sim','nao'], true)) return 'Informe sua cidade de residência.';
        if (strlen(digits((string)$d['cep'])) !== 8) return 'Informe um CEP com 8 dígitos.';
        foreach (['logradouro'=>150,'numero'=>15,'complemento'=>100,'bairro'=>100,'cidade'=>100] as $campo=>$limite)
            if ($tamanho($campo) > $limite) return 'Um dos campos do endereço está longo demais.';
        $ufs = explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO');
        if (!in_array($d['estado'], $ufs, true)) return 'Selecione um estado válido.';
    } elseif ($passo === 3) {
        if (!$exigir(['nome_escola','serie_escolar','origem_divulgacao'])) return 'Preencha as informações escolares.';
        if ($tamanho('nome_escola') > 150 || $tamanho('serie_escolar') > 80) return 'Confira o nome da escola e a série.';
        $opcoes = ['Instagram / Redes sociais','Escola (Diretoria / Professores)','Amigos ou familiares','Anúncio no rádio ou TV','Outros'];
        if (!in_array($d['origem_divulgacao'], $opcoes, true)) return 'Selecione como conheceu o programa.';
    } elseif ($passo === 4) {
        $idade = gtIdade((string)($d['data_nascimento'] ?? ''));
        if ($idade === null) return 'Volte à primeira etapa e confira a data de nascimento.';
        $temResp = trim((string)($d['responsavel_nome'] ?? '')) !== '' || trim((string)($d['parentesco'] ?? '')) !== '' || trim((string)($d['responsavel_telefone'] ?? '')) !== '';
        if ($idade < 18 || $temResp) {
            if (!$exigir(['responsavel_nome','parentesco','responsavel_telefone'])) return 'Informe todos os dados do responsável.';
            if ($tamanho('responsavel_nome') > 150) return 'Nome do responsável muito longo.';
            if (!in_array($d['parentesco'], ['Mãe','Pai','Tutor(a) / Responsável legal','Outro'], true)) return 'Selecione o parentesco.';
            if (strlen(digits((string)$d['responsavel_telefone'])) !== 11) return 'Informe um WhatsApp válido do responsável.';
        }
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A validação CSRF também é aplicada ao navegar, não só ao gravar no MySQL.
    $csrfRecebido = $_POST['csrf'] ?? null;
    if (!is_string($csrfRecebido) || !hash_equals(token(), $csrfRecebido)) {
        http_response_code(403);
        $erroEtapa = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif (isset($_POST['voltar']) || isset($_POST['continuar'])) {
        if (isset($camposPorEtapa[$etapa])) {
            foreach ($camposPorEtapa[$etapa] as $campo) {
                $valor = $_POST[$campo] ?? '';
                if (is_string($valor)) $dadosEtapas[$campo] = trim($valor);
            }
        }
        if (isset($_POST['voltar'])) {
            $etapa = max(1, $etapa - 1);
        } elseif ($etapa < 5) {
            $erroEtapa = gtValidarEtapa($etapa, $dadosEtapas);
            if ($erroEtapa === '') {
                $_SESSION['gt_concluidas'][$etapa] = true;
                // Se a pessoa corrigir etapa anterior, as etapas posteriores devem ser validadas de novo na finalização.
                $etapa = min(5, $etapa + 1);
            } else {
                unset($_SESSION['gt_concluidas'][$etapa]);
            }
        }
        $_SESSION['gt_etapa'] = $etapa;
    }
}
