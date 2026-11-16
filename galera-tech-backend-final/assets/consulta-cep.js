(() => {
  'use strict';
  const cepInput = document.getElementById('cep');
  const aviso = document.getElementById('cep-status');
  const form = document.getElementById('formInscricao');
  if (!cepInput || !aviso || !form) return;

  const campos = {
    logradouro: document.getElementById('logradouro'),
    bairro: document.getElementById('bairro'),
    cidade: document.getElementById('cidade'),
    estado: document.getElementById('estado')
  };
  const soNumeros = valor => valor.replace(/\D/g, '').slice(0, 8);
  let ultimaConsulta = '';
  let controlador = null;
  let pendente = null;
  let atraso = null;
  let valoresPreenchidos = {};

  function avisar(texto, status = '') {
    aviso.textContent = texto;
    aviso.dataset.status = status;
  }

  function limparAutomaticos() {
    Object.entries(valoresPreenchidos).forEach(([campo, valor]) => {
      if (campos[campo] && campos[campo].value === valor) campos[campo].value = '';
    });
    valoresPreenchidos = {};
  }

  function aplicarDados(dados) {
    const endereco = {
      logradouro: dados.logradouro || '',
      bairro: dados.bairro || '',
      cidade: dados.localidade || '',
      estado: dados.uf || ''
    };
    for (const [campo, valor] of Object.entries(endereco)) {
      if (!campos[campo] || !valor) continue;
      campos[campo].value = valor;
      valoresPreenchidos[campo] = valor;
      campos[campo].dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  async function consultar(cep) {
    if (controlador) controlador.abort();
    controlador = new AbortController();
    const sinal = controlador.signal;
    const timeout = setTimeout(() => controlador.abort(), 9000);
    avisar('Consultando CEP...', 'loading');
    try {
      const resposta = await fetch(`https://viacep.com.br/ws/${cep}/json/`, {
        signal: sinal,
        headers: { Accept: 'application/json' }
      });
      if (!resposta.ok) throw new Error('Falha no serviço');
      const dados = await resposta.json();
      if (sinal.aborted || soNumeros(cepInput.value) !== cep) return;
      if (dados.erro) {
        avisar('CEP não encontrado. Confira o número ou preencha o endereço manualmente.', 'warning');
        return;
      }
      aplicarDados(dados);
      avisar('Endereço encontrado. Confira os dados e informe o número.', 'success');
    } catch (erro) {
      if (sinal.aborted && soNumeros(cepInput.value) !== cep) return;
      avisar('Não foi possível consultar o CEP. Preencha o endereço manualmente.', 'warning');
    } finally {
      clearTimeout(timeout);
    }
  }

  function agendar() {
    const cep = soNumeros(cepInput.value);
    cepInput.value = cep.length > 5 ? `${cep.slice(0, 5)}-${cep.slice(5)}` : cep;
    clearTimeout(atraso);
    if (controlador) controlador.abort();
    controlador = null;
    pendente = null;
    if (cep !== ultimaConsulta) {
      limparAutomaticos();
      ultimaConsulta = cep;
    }
    if (cep.length !== 8) {
      avisar(cep.length ? 'Digite os 8 números do CEP.' : 'Informe o CEP para buscar o endereço automaticamente.');
      return;
    }
    avisar('Preparando consulta...', 'loading');
    atraso = setTimeout(() => {
      pendente = consultar(cep);
      const estaConsulta = pendente;
      pendente.finally(() => { if (pendente === estaConsulta) pendente = null; });
    }, 300);
  }

  cepInput.addEventListener('input', agendar);
  cepInput.addEventListener('blur', () => {
    const cep = soNumeros(cepInput.value);
    if (cep.length === 8 && !pendente && atraso) {
      clearTimeout(atraso);
      atraso = null;
      pendente = consultar(cep);
      const estaConsulta = pendente;
      pendente.finally(() => { if (pendente === estaConsulta) pendente = null; });
    }
  });
  let liberandoEnvio = false;
  form.addEventListener('submit', async evento => {
    if (liberandoEnvio) {
      liberandoEnvio = false;
      return;
    }
    const botao = evento.submitter;
    if (!botao || botao.name !== 'continuar') return;
    if (pendente || atraso) {
      evento.preventDefault();
      clearTimeout(atraso);
      atraso = null;
      if (!pendente && soNumeros(cepInput.value).length === 8) {
        pendente = consultar(soNumeros(cepInput.value));
      }
      if (pendente) await pendente;
      liberandoEnvio = true;
      form.requestSubmit(botao);
    }
  });
})();
