--
-- PostgreSQL database dump
--

\restrict 8ykVakb5kiROgKFRHbbjU5WzneoncJB9MxJEsOTtqORGwmXnBZhjRClqKJtbnAC

-- Dumped from database version 16.10 (Ubuntu 16.10-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.10 (Ubuntu 16.10-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

-- *not* creating schema, since initdb creates it


--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS '';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: auditoria; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auditoria (
    id character varying(36) NOT NULL,
    entidade character varying(50) NOT NULL,
    entidade_id character varying(36) NOT NULL,
    entidade_relacionada character varying(50),
    entidade_relacionada_id character varying(36),
    acao character varying(30) NOT NULL,
    sub_acao character varying(50),
    dados_anteriores jsonb,
    dados_novos jsonb,
    metadados jsonb,
    usuario_id character varying(36),
    sessao_id character varying(100),
    ip_address character varying(45),
    user_agent character varying(500),
    data_acao timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    observacoes text
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: concessionaria_usuario; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.concessionaria_usuario (
    concessionaria_id character(26) NOT NULL,
    usuario_id character(26) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: concessionarias; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.concessionarias (
    id character(26) NOT NULL,
    nome character varying(255) NOT NULL,
    estado character varying(2) NOT NULL,
    cnpj character varying(14) NOT NULL,
    ano integer NOT NULL,
    reh character varying(255),
    mes_revisao integer,
    logo_url text,
    a3a_conv_az_tusd_dem_p numeric(10,8),
    a3a_conv_az_tusd_con_p numeric(10,8),
    a3a_conv_az_tusd_te_p numeric(10,8),
    a3a_conv_az_tusd_dem_fp numeric(10,8),
    a3a_conv_az_tusd_con_fp numeric(10,8),
    a3a_conv_az_tusd_te_fp numeric(10,8),
    a3a_scee_az_tusd_dem_p numeric(10,8),
    a3a_scee_az_tusd_con_p numeric(10,8),
    a3a_scee_az_tusd_te_p numeric(10,8),
    a3a_scee_az_tusd_dem_fp numeric(10,8),
    a3a_scee_az_tusd_con_fp numeric(10,8),
    a3a_scee_az_tusd_te_fp numeric(10,8),
    a3a_conv_vd_tusd_dem numeric(10,8),
    a3a_conv_vd_tusd_con_p numeric(10,8),
    a3a_conv_vd_tusd_te_p numeric(10,8),
    a3a_conv_vd_tusd_con_fp numeric(10,8),
    a3a_conv_vd_tusd_te_fp numeric(10,8),
    a3a_scee_vd_tusd_dem numeric(10,8),
    a3a_scee_vd_tusd_con_p numeric(10,8),
    a3a_scee_vd_tusd_te_p numeric(10,8),
    a3a_scee_vd_tusd_con_fp numeric(10,8),
    a3a_scee_vd_tusd_te_fp numeric(10,8),
    a3a_az_tusd_dem_g numeric(10,8),
    a4_conv_az_tusd_dem_p numeric(10,8),
    a4_conv_az_tusd_con_p numeric(10,8),
    a4_conv_az_tusd_te_p numeric(10,8),
    a4_conv_az_tusd_dem_fp numeric(10,8),
    a4_conv_az_tusd_con_fp numeric(10,8),
    a4_conv_az_tusd_te_fp numeric(10,8),
    a4_scee_az_tusd_dem_p numeric(10,8),
    a4_scee_az_tusd_con_p numeric(10,8),
    a4_scee_az_tusd_te_p numeric(10,8),
    a4_scee_az_tusd_dem_fp numeric(10,8),
    a4_scee_az_tusd_con_fp numeric(10,8),
    a4_scee_az_tusd_te_fp numeric(10,8),
    a4_conv_vd_tusd_dem numeric(10,8),
    a4_conv_vd_tusd_con_p numeric(10,8),
    a4_conv_vd_tusd_te_p numeric(10,8),
    a4_conv_vd_tusd_con_fp numeric(10,8),
    a4_conv_vd_tusd_te_fp numeric(10,8),
    a4_scee_vd_tusd_dem numeric(10,8),
    a4_scee_vd_tusd_con_p numeric(10,8),
    a4_scee_vd_tusd_te_p numeric(10,8),
    a4_scee_vd_tusd_con_fp numeric(10,8),
    a4_scee_vd_tusd_te_fp numeric(10,8),
    a4_az_tusd_dem_g numeric(10,8),
    b3_conv_tusd numeric(10,8),
    b3_conv_te numeric(10,8),
    b3_scee_tusd numeric(10,8),
    b3_scee_te numeric(10,8),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: configuracoes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.configuracoes (
    id character varying(36) NOT NULL,
    chave character varying(50) NOT NULL,
    valor text NOT NULL,
    tipo character varying(255) DEFAULT 'string'::character varying NOT NULL,
    descricao text,
    grupo character varying(50) DEFAULT 'geral'::character varying NOT NULL,
    updated_by character varying(36),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT configuracoes_tipo_check CHECK (((tipo)::text = ANY (ARRAY[('string'::character varying)::text, ('number'::character varying)::text, ('boolean'::character varying)::text, ('json'::character varying)::text])))
);


--
-- Name: TABLE configuracoes; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.configuracoes IS 'Configurações globais do sistema';


--
-- Name: COLUMN configuracoes.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.configuracoes.id IS 'UUID da configuração';


--
-- Name: COLUMN configuracoes.chave; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.configuracoes.chave IS 'Chave única da configuração';


--
-- Name: COLUMN configuracoes.valor; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.configuracoes.valor IS 'Valor da configuração (pode ser JSON)';


--
-- Name: COLUMN configuracoes.tipo; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.configuracoes.tipo IS 'Tipo do valor para validação';


--
-- Name: COLUMN configuracoes.descricao; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.configuracoes.descricao IS 'Descrição da configuração';


--
-- Name: COLUMN configuracoes.grupo; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.configuracoes.grupo IS 'Grupo da configuração (geral, calibragem, sistema, propostas)';


--
-- Name: COLUMN configuracoes.updated_by; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.configuracoes.updated_by IS 'ID do usuário que fez a última alteração';


--
-- Name: consorcios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consorcios (
    id character(26) NOT NULL,
    organizacao_id character(26) NOT NULL,
    nome character varying(255) NOT NULL,
    cnpj character varying(255) NOT NULL,
    ato character varying(255) NOT NULL,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: controle_clube; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.controle_clube (
    id character varying(36) NOT NULL,
    proposta_id character varying(36) NOT NULL,
    uc_id character varying(36) NOT NULL,
    ug_id character varying(36),
    observacoes text,
    data_entrada_controle timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    calibragem numeric(5,2) DEFAULT 0 NOT NULL,
    valor_calibrado numeric(10,2),
    status_troca character varying(255) DEFAULT 'Esteira'::character varying NOT NULL,
    data_titularidade date DEFAULT '2025-08-20'::date NOT NULL,
    calibragem_individual numeric(10,2),
    desconto_tarifa character varying(10),
    desconto_bandeira character varying(10),
    documentacao_troca_titularidade character varying(255),
    CONSTRAINT chk_controle_desconto_bandeira_format CHECK (((desconto_bandeira)::text ~ '^[0-9]+(\.[0-9]+)?%$'::text)),
    CONSTRAINT chk_controle_desconto_tarifa_format CHECK (((desconto_tarifa)::text ~ '^[0-9]+(\.[0-9]+)?%$'::text)),
    CONSTRAINT controle_clube_status_troca_check CHECK (((status_troca)::text = ANY (ARRAY[('Esteira'::character varying)::text, ('Em andamento'::character varying)::text, ('Associado'::character varying)::text])))
);


--
-- Name: TABLE controle_clube; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.controle_clube IS 'Controle de propostas fechadas com UCs e UGs atribuídas';


--
-- Name: COLUMN controle_clube.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.controle_clube.id IS 'UUID do controle';


--
-- Name: COLUMN controle_clube.proposta_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.controle_clube.proposta_id IS 'ID da proposta fechada';


--
-- Name: COLUMN controle_clube.uc_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.controle_clube.uc_id IS 'ID da unidade consumidora';


--
-- Name: COLUMN controle_clube.ug_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.controle_clube.ug_id IS 'ID da usina geradora atribuída (UC que é UG)';


--
-- Name: COLUMN controle_clube.observacoes; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.controle_clube.observacoes IS 'Observações do controle';


--
-- Name: COLUMN controle_clube.data_entrada_controle; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.controle_clube.data_entrada_controle IS 'Data de entrada no controle';


--
-- Name: COLUMN controle_clube.calibragem_individual; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.controle_clube.calibragem_individual IS 'Calibragem individual da UC (sobrescreve calibragem global quando preenchida)';


--
-- Name: COLUMN controle_clube.documentacao_troca_titularidade; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.controle_clube.documentacao_troca_titularidade IS 'Nome do arquivo da declaração de troca de titularidade';


--
-- Name: documentos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.documentos (
    id character(26) NOT NULL,
    nome character varying(255) NOT NULL,
    nome_original character varying(255) NOT NULL,
    tipo character varying(255) NOT NULL,
    mime_type character varying(255) NOT NULL,
    tamanho bigint NOT NULL,
    descricao text,
    caminho_s3 character varying(255) NOT NULL,
    usuario_id character(26) NOT NULL,
    organizacao_id character(26),
    concessionaria_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.documents (
    id character varying(26),
    autentique_id character varying(255),
    name character varying(255),
    status character varying(255),
    is_sandbox boolean,
    proposta_id character varying(36),
    document_data text,
    signers text,
    autentique_response text,
    total_signers integer,
    signed_count integer,
    rejected_count integer,
    autentique_created_at timestamp(0) without time zone,
    last_checked_at timestamp(0) without time zone,
    created_by character varying(36),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone,
    cancelled_by character varying(36),
    uploaded_manually boolean,
    uploaded_at timestamp(0) without time zone,
    uploaded_by character varying(36),
    manual_upload_filename character varying(255),
    signer_email character varying(255),
    signer_name character varying(255),
    signing_url text,
    envio_whatsapp boolean,
    envio_email boolean,
    numero_uc character varying(255)
);


--
-- Name: COLUMN documents.numero_uc; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.documents.numero_uc IS 'Número da UC específica do termo (para permitir múltiplos termos por proposta)';


--
-- Name: enderecos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.enderecos (
    id character(26) NOT NULL,
    endereco_curto character varying(255),
    logradouro character varying(255) NOT NULL,
    numero character varying(255) NOT NULL,
    complemento character varying(255),
    bairro character varying(255) NOT NULL,
    cidade character varying(255) NOT NULL,
    estado character varying(2) NOT NULL,
    pais character varying(255) DEFAULT 'Brasil'::character varying NOT NULL,
    cep character varying(9),
    referencia character varying(255),
    latitude numeric(10,8),
    longitude numeric(11,8),
    enderecavel_id character(26) NOT NULL,
    enderecavel_tipo character varying(255) NOT NULL,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: faturas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.faturas (
    id character(26) NOT NULL,
    concessionaria_id character(26) NOT NULL,
    unidade_consumidora_id character(26) NOT NULL,
    mes_ref character varying(7) NOT NULL,
    compensacao_habilitada boolean DEFAULT false NOT NULL,
    conta_link_concessionaria text,
    valor_concessionaria numeric(12,2),
    vencimento_concessionaria date,
    status_concessionaria character varying(255),
    conta_link_consorcio text,
    valor_consorcio numeric(12,2),
    vencimento_consorcio date,
    status_consorcio character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: feedbacks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feedbacks (
    id character(26) NOT NULL,
    status character varying(255) NOT NULL,
    usuario_id character(26) NOT NULL,
    tipo character varying(255) NOT NULL,
    descricao text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT feedbacks_tipo_check CHECK (((tipo)::text = ANY (ARRAY[('bug'::character varying)::text, ('sugestão'::character varying)::text])))
);


--
-- Name: historicos_faturamento; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.historicos_faturamento (
    id character(26) NOT NULL,
    unidade_consumidora_id character(26) NOT NULL,
    concessionaria_id character(26),
    id_regra_faturamento character varying(255),
    uc character varying(255),
    cliente character varying(255),
    grupo character varying(1),
    status character varying(255),
    data_leitura date,
    mes_referencia character varying(7),
    bandeira character varying(255),
    valor_bandeira numeric(12,2),
    conta_link text,
    adc_bandeira_amarela numeric(12,2),
    rs_adc_bandeira_amarela numeric(12,4),
    valor_adc_bandeira_amarela numeric(12,2),
    adc_bandeira_amarela_hr numeric(12,2),
    rs_adc_bandeira_amarela_hr numeric(12,4),
    valor_adc_bandeira_amarela_hr numeric(12,2),
    adc_bandeira_amarela_fp numeric(12,2),
    rs_adc_bandeira_amarela_fp numeric(12,4),
    valor_adc_bandeira_amarela_fp numeric(12,2),
    adc_bandeira_amarela_p numeric(12,2),
    rs_adc_bandeira_amarela_p numeric(12,4),
    valor_adc_bandeira_amarela_p numeric(12,2),
    adc_bandeira_amarela_hi numeric(12,2),
    rs_adc_bandeira_amarela_hi numeric(12,4),
    valor_adc_bandeira_amarela_hi numeric(12,2),
    adc_bandeira_vermelha numeric(12,2),
    rs_adc_bandeira_vermelha numeric(12,4),
    valor_adc_bandeira_vermelha numeric(12,2),
    adc_bandeira_vermelha_p numeric(12,2),
    rs_adc_bandeira_vermelha_p numeric(12,4),
    valor_adc_bandeira_vermelha_p numeric(12,2),
    adc_bandeira_vermelha_fp numeric(12,2),
    rs_adc_bandeira_vermelha_fp numeric(12,4),
    valor_adc_bandeira_vermelha_fp numeric(12,2),
    adc_bandeira_vermelha_hr numeric(12,2),
    rs_adc_bandeira_vermelha_hr numeric(12,4),
    valor_adc_bandeira_vermelha_hr numeric(12,2),
    adc_bandeira_vermelha_hi numeric(12,2),
    rs_adc_bandeira_vermelha_hi numeric(12,4),
    valor_adc_bandeira_vermelha_hi numeric(12,2),
    consumo_n_comp numeric(12,2),
    rs_consumo_n_comp numeric(12,4),
    valor_consumo_n_comp numeric(12,2),
    consumo_n_comp_p_tusd numeric(12,2),
    rs_consumo_n_comp_p_tusd numeric(12,4),
    valor_consumo_n_comp_p_tusd numeric(12,2),
    consumo_n_comp_fp_tusd numeric(12,2),
    rs_consumo_n_comp_fp_tusd numeric(12,4),
    valor_consumo_n_comp_fp_tusd numeric(12,2),
    consumo_n_comp_hr_tusd numeric(12,2),
    rs_consumo_n_comp_hr_tusd numeric(12,4),
    valor_consumo_n_comp_hr_tusd numeric(12,2),
    consumo_n_comp_p_te numeric(12,2),
    rs_consumo_n_comp_p_te numeric(12,4),
    valor_consumo_n_comp_p_te numeric(12,2),
    consumo_n_comp_fp_te numeric(12,2),
    rs_consumo_n_comp_fp_te numeric(12,4),
    valor_consumo_n_comp_fp_te numeric(12,2),
    consumo_n_comp_hr_te numeric(12,2),
    rs_consumo_n_comp_hr_te numeric(12,4),
    valor_consumo_n_comp_hr_te numeric(12,2),
    consumo_comp numeric(12,2),
    rs_consumo_comp numeric(12,4),
    valor_consumo_comp numeric(12,2),
    consumo_comp_p_tusd numeric(12,2),
    rs_consumo_comp_p_tusd numeric(12,4),
    valor_consumo_comp_p_tusd numeric(12,2),
    consumo_comp_fp_tusd numeric(12,2),
    rs_consumo_comp_fp_tusd numeric(12,4),
    valor_consumo_comp_fp_tusd numeric(12,2),
    consumo_comp_hr_tusd numeric(12,2),
    rs_consumo_comp_hr_tusd numeric(12,4),
    valor_consumo_comp_hr_tusd numeric(12,2),
    consumo_comp_p_te numeric(12,2),
    rs_consumo_comp_p_te numeric(12,4),
    valor_consumo_comp_p_te numeric(12,2),
    consumo_comp_fp_te numeric(12,2),
    rs_consumo_comp_fp_te numeric(12,4),
    valor_consumo_comp_fp_te numeric(12,2),
    consumo_comp_hr_te numeric(12,2),
    rs_consumo_comp_hr_te numeric(12,4),
    valor_consumo_comp_hr_te numeric(12,2),
    consumo numeric(12,2),
    rs_consumo numeric(12,4),
    valor_consumo numeric(12,2),
    consumo_p_tusd numeric(12,2),
    rs_consumo_p_tusd numeric(12,4),
    valor_consumo_p_tusd numeric(12,2),
    consumo_fp_tusd numeric(12,2),
    rs_consumo_fp_tusd numeric(12,4),
    valor_consumo_fp_tusd numeric(12,2),
    consumo_hr_tusd numeric(12,2),
    rs_consumo_hr_tusd numeric(12,4),
    valor_consumo_hr_tusd numeric(12,2),
    consumo_p_te numeric(12,2),
    rs_consumo_p_te numeric(12,4),
    valor_consumo_p_te numeric(12,2),
    consumo_fp_te numeric(12,2),
    rs_consumo_fp_te numeric(12,4),
    valor_consumo_fp_te numeric(12,2),
    consumo_hr_te numeric(12,2),
    rs_consumo_hr_te numeric(12,4),
    valor_consumo_hr_te numeric(12,2),
    consumo_p numeric(12,2),
    rs_consumo_p numeric(12,4),
    valor_consumo_p numeric(12,2),
    consumo_fp numeric(12,2),
    rs_consumo_fp numeric(12,4),
    valor_consumo_fp numeric(12,2),
    consumo_hr numeric(12,2),
    rs_consumo_hr numeric(12,4),
    valor_consumo_hr numeric(12,2),
    consumo_hi numeric(12,2),
    rs_consumo_hi numeric(12,4),
    valor_consumo_hi numeric(12,2),
    consumo_comp_hi numeric(12,2),
    rs_consumo_comp_hi numeric(12,4),
    valor_consumo_comp_hi numeric(12,2),
    consumo_n_comp_hi numeric(12,2),
    rs_consumo_n_comp_hi numeric(12,4),
    valor_consumo_n_comp_hi numeric(12,2),
    valor_credito_consumo numeric(12,2),
    energia_injetada numeric(12,2),
    rs_energia_injetada numeric(12,4),
    valor_energia_injetada numeric(12,2),
    rs_energia_injetada_tusd_p numeric(12,4),
    rs_energia_injetada_te_p numeric(12,4),
    valor_energia_injetada_tusd_p numeric(12,2),
    valor_energia_injetada_te_p numeric(12,2),
    rs_energia_injetada_tusd_fp numeric(12,4),
    rs_energia_injetada_te_fp numeric(12,4),
    valor_energia_injetada_tusd_fp numeric(12,2),
    valor_energia_injetada_te_fp numeric(12,2),
    rs_energia_injetada_tusd_hr numeric(12,4),
    rs_energia_injetada_te_hr numeric(12,4),
    valor_energia_injetada_tusd_hr numeric(12,2),
    valor_energia_injetada_te_hr numeric(12,2),
    energia_injetada_hi numeric(12,2),
    rs_energia_injetada_hi numeric(12,4),
    valor_energia_injetada_hi numeric(12,2),
    demanda_faturada numeric(12,2),
    rs_demanda_faturada numeric(12,4),
    valor_demanda numeric(12,2),
    demanda_faturada_p numeric(12,2),
    rs_demanda_faturada_p numeric(12,4),
    demanda_faturada_fp numeric(12,2),
    rs_demanda_faturada_fp numeric(12,4),
    demanda_faturada_hr numeric(12,2),
    rs_demanda_faturada_hr numeric(12,4),
    demanda_ultrapassagem numeric(12,2),
    rs_demanda_ultrapassagem numeric(12,4),
    valor_demanda_ultrapassagem numeric(12,2),
    demanda_ultrapassagem_p numeric(12,2),
    rs_demanda_ultrapassagem_p numeric(12,4),
    demanda_ultrapassagem_fp numeric(12,2),
    rs_demanda_ultrapassagem_fp numeric(12,4),
    demanda_ultrapassagem_hr numeric(12,2),
    rs_demanda_ultrapassagem_hr numeric(12,4),
    demanda_ultrapassagem_geracao numeric(12,2),
    rs_demanda_ultrapassagem_geracao numeric(12,4),
    valor_demanda_ultra_geracao numeric(12,2),
    demanda_geracao numeric(12,2),
    rs_demanda_geracao numeric(12,4),
    valor_demanda_geracao numeric(12,2),
    demanda_isento_faturada numeric(12,2),
    rs_demanda_isento_faturada numeric(12,4),
    valor_demanda_isento numeric(12,2),
    demanda_contratada numeric(12,2),
    ufer_p numeric(12,2),
    rs_ufer_p numeric(12,4),
    valor_ufer_p numeric(12,2),
    ufer_fp numeric(12,2),
    rs_ufer_fp numeric(12,4),
    valor_ufer_fp numeric(12,2),
    ufer_hr numeric(12,2),
    rs_ufer_hr numeric(12,4),
    valor_ufer_hr numeric(12,2),
    ufer numeric(12,2),
    rs_ufer numeric(12,4),
    valor_ufer numeric(12,2),
    valor_parc_injet numeric(12,2),
    valor_subsidio_parc_injet_liquido numeric(12,2),
    valor_energia_comp_nao_insenta numeric(12,2),
    valor_ir numeric(12,2),
    dmcr numeric(12,2),
    rs_dmcr numeric(12,4),
    valor_dmcr numeric(12,2),
    valor_iluminacao numeric(12,2),
    valor_juros numeric(12,2),
    valor_multa numeric(12,2),
    valor_dic numeric(12,2),
    valor_fic numeric(12,2),
    valor_dmic numeric(12,2),
    dif_demanda numeric(12,2),
    rs_dif_demanda numeric(12,4),
    valor_dif_demanda numeric(12,2),
    valor_fatura_duplicada numeric(12,2),
    valor_adicional numeric(12,2),
    valor_correcao_ipca numeric(12,2),
    valor_bonus_itaipu numeric(12,2),
    valor_beneficio_bruto numeric(12,2),
    valor_beneficio_liquido numeric(12,2),
    medidor character varying(255),
    leitura_atual_energia_ativa numeric(15,2),
    leitura_anterior_energia_ativa numeric(15,2),
    const_medidor_energia_ativa numeric(8,2),
    leitura_atual_energia_geracao numeric(15,2),
    leitura_anterior_energia_geracao numeric(15,2),
    const_medidor_energia_geracao numeric(8,2),
    leitura_atual_energia_ativa_p numeric(15,2),
    leitura_atual_energia_ativa_fp numeric(15,2),
    leitura_atual_energia_ativa_hr numeric(15,2),
    leitura_anterior_energia_ativa_p numeric(15,2),
    leitura_anterior_energia_ativa_fp numeric(15,2),
    leitura_anterior_energia_ativa_hr numeric(15,2),
    const_medidor_energia_ativa_p numeric(8,2),
    const_medidor_energia_ativa_fp numeric(8,2),
    const_medidor_energia_ativa_hr numeric(8,2),
    leitura_atual_energia_geracao_p numeric(15,2),
    leitura_atual_energia_geracao_fp numeric(15,2),
    leitura_atual_energia_geracao_hr numeric(15,2),
    leitura_anterior_energia_geracao_p numeric(15,2),
    leitura_anterior_energia_geracao_fp numeric(15,2),
    leitura_anterior_energia_geracao_hr numeric(15,2),
    const_medidor_energia_geracao_p numeric(8,2),
    const_medidor_energia_geracao_fp numeric(8,2),
    const_medidor_energia_geracao_hr numeric(8,2),
    leitura_atual_demanda_p numeric(15,2),
    leitura_atual_demanda_fp numeric(15,2),
    leitura_atual_demanda_hr numeric(15,2),
    leitura_anterior_demanda_p numeric(15,2),
    leitura_anterior_demanda_fp numeric(15,2),
    leitura_anterior_demanda_hr numeric(15,2),
    const_medidor_demanda_p numeric(8,2),
    const_medidor_demanda_fp numeric(8,2),
    const_medidor_demanda_hr numeric(8,2),
    leitura_atual_demanda_geracao_p numeric(15,2),
    leitura_atual_demanda_geracao_fp numeric(15,2),
    leitura_atual_demanda_geracao_hr numeric(15,2),
    leitura_anterior_demanda_geracao_p numeric(15,2),
    leitura_anterior_demanda_geracao_fp numeric(15,2),
    leitura_anterior_demanda_geracao_hr numeric(15,2),
    const_medidor_demanda_geracao_p numeric(8,2),
    const_medidor_demanda_geracao_fp numeric(8,2),
    const_medidor_demanda_geracao_hr numeric(8,2),
    leitura_atual_ufer_p numeric(15,2),
    leitura_atual_ufer_fp numeric(15,2),
    leitura_atual_ufer_hr numeric(15,2),
    leitura_anterior_ufer_p numeric(15,2),
    leitura_anterior_ufer_fp numeric(15,2),
    leitura_anterior_ufer_hr numeric(15,2),
    const_medidor_ufer_p numeric(8,2),
    const_medidor_ufer_fp numeric(8,2),
    const_medidor_ufer_hr numeric(8,2),
    leitura_atual_dmcr_p numeric(15,2),
    leitura_atual_dmcr_fp numeric(15,2),
    leitura_atual_dmcr_hr numeric(15,2),
    leitura_anterior_dmcr_p numeric(15,2),
    leitura_anterior_dmcr_fp numeric(15,2),
    leitura_anterior_dmcr_hr numeric(15,2),
    const_medidor_dmcr_p numeric(8,2),
    const_medidor_dmcr_fp numeric(8,2),
    const_medidor_dmcr_hr numeric(8,2),
    vencimento date,
    excedente_recebido numeric(12,2),
    excedente_recebido_p numeric(12,2),
    excedente_recebido_fp numeric(12,2),
    excedente_recebido_hr numeric(12,2),
    saldo numeric(12,2),
    saldo_p numeric(12,2),
    saldo_fp numeric(12,2),
    saldo_hr numeric(12,2),
    saldo_hi numeric(12,2),
    saldo_30 numeric(12,2),
    saldo_60 numeric(12,2),
    saldo_30_p numeric(12,2),
    saldo_30_fp numeric(12,2),
    saldo_30_hr numeric(12,2),
    saldo_60_p numeric(12,2),
    saldo_60_fp numeric(12,2),
    saldo_60_hr numeric(12,2),
    saldo_teorico numeric(12,2),
    rateio_fatura numeric(12,2),
    valor_fatura numeric(12,2),
    aliquota_icms numeric(5,2),
    aliquota_pis numeric(5,4),
    aliquota_cofins numeric(5,4),
    valor_icms numeric(12,2),
    valor_pis numeric(12,2),
    valor_cofins numeric(12,2),
    ug character varying(255),
    geracao_ciclo numeric(12,2),
    geracao_ciclo_p numeric(12,2),
    geracao_ciclo_fp numeric(12,2),
    geracao_ciclo_hr numeric(12,2),
    ugs_geradoras character varying(255),
    credito_recebido numeric(12,2),
    credito_recebido_p numeric(12,2),
    credito_recebido_fp numeric(12,2),
    credito_recebido_hr numeric(12,2),
    credito_recebido_total numeric(12,2),
    tarifa_comp numeric(12,4),
    rs_s_desconto numeric(12,4),
    rs_economia numeric(12,4),
    rs_aupus numeric(12,4),
    rs_boleto numeric(12,4),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id character(26) NOT NULL
);


--
-- Name: model_has_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id character(26) NOT NULL
);


--
-- Name: notificacoes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notificacoes (
    id character(26) NOT NULL,
    usuario_id character(26) NOT NULL,
    titulo character varying(255) NOT NULL,
    descricao text,
    lida boolean DEFAULT false NOT NULL,
    tipo character varying(255) DEFAULT 'info'::character varying NOT NULL,
    link character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: oportunidades; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oportunidades (
    id character(26) NOT NULL,
    tipo character varying(255) NOT NULL,
    usuario_id character(26) NOT NULL,
    admin_responsavel_id character(26),
    concessionaria_id character(26) NOT NULL,
    organizacao_id character(26) NOT NULL,
    observacao character varying(255) NOT NULL,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organizacao_usuario; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizacao_usuario (
    organizacao_id character(26) NOT NULL,
    usuario_id character(26) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organizacoes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizacoes (
    id character(26) NOT NULL,
    nome character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    documento character varying(255),
    preferencias json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    deleted_by character(26)
);


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    tokenable_id character(26) NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: propostas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.propostas (
    id character varying(36) NOT NULL,
    numero_proposta character varying(50) NOT NULL,
    data_proposta date NOT NULL,
    nome_cliente character varying(200) NOT NULL,
    usuario_id character varying(36) NOT NULL,
    recorrencia character varying(10) DEFAULT '3%'::character varying NOT NULL,
    desconto_tarifa character varying(10) DEFAULT '20%'::character varying NOT NULL,
    desconto_bandeira character varying(10) DEFAULT '20%'::character varying NOT NULL,
    observacoes text,
    beneficios json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    unidades_consumidoras json,
    documentacao json,
    inflacao numeric(5,2) DEFAULT '2'::numeric NOT NULL,
    tarifa_tributos numeric(8,4),
    consultor_id character varying(36)
);


--
-- Name: TABLE propostas; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.propostas IS 'Tabela principal de propostas de energia solar';


--
-- Name: COLUMN propostas.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.id IS 'UUID da proposta';


--
-- Name: COLUMN propostas.numero_proposta; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.numero_proposta IS 'Número único da proposta';


--
-- Name: COLUMN propostas.data_proposta; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.data_proposta IS 'Data de criação da proposta';


--
-- Name: COLUMN propostas.nome_cliente; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.nome_cliente IS 'Nome do cliente/empresa';


--
-- Name: COLUMN propostas.usuario_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.usuario_id IS 'ID do usuário que criou a proposta';


--
-- Name: COLUMN propostas.recorrencia; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.recorrencia IS 'Percentual de recorrência';


--
-- Name: COLUMN propostas.observacoes; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.observacoes IS 'Observações gerais da proposta';


--
-- Name: COLUMN propostas.beneficios; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.beneficios IS 'Lista de benefícios selecionados para esta proposta';


--
-- Name: COLUMN propostas.documentacao; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.documentacao IS 'Documentação da proposta: CPF/CNPJ, contratos, endereços, etc.';


--
-- Name: COLUMN propostas.inflacao; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.inflacao IS 'Percentual de inflação anual';


--
-- Name: COLUMN propostas.tarifa_tributos; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.tarifa_tributos IS 'Valor da tarifa com tributos em R$/kWh';


--
-- Name: COLUMN propostas.consultor_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.propostas.consultor_id IS 'ID do consultor responsável (FK para usuarios)';


--
-- Name: prospects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.prospects (
    id character(26) NOT NULL,
    usuario_id character(26) NOT NULL,
    organizacao_id character(26),
    nome character varying(255),
    cpf_cnpj character varying(255),
    tipo_fornecimento character varying(255),
    grupo character varying(255),
    cidade character varying(255),
    estado character varying(255),
    status character varying(255),
    ucs json,
    valor_fatura numeric(10,2),
    quant_consumo_medio numeric(10,2),
    instagram character varying(255),
    whatsapp character varying(255),
    link_fatura character varying(255),
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: rateios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rateios (
    id character(26) NOT NULL,
    usuario_admin_id character(26),
    unidade_geradora_id character(26) NOT NULL,
    unidade_beneficiaria_id character(26) NOT NULL,
    consorcio_id character(26),
    organizacao_id character(26),
    data_rateio timestamp(0) without time zone NOT NULL,
    percentual numeric(5,2) NOT NULL,
    kwh_compensado numeric(10,2) NOT NULL,
    tipo character varying(255) NOT NULL,
    compensacao_habilitada boolean NOT NULL,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: role_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id character(26),
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: telefones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.telefones (
    id character(26) NOT NULL,
    numero_telefone character varying(255) NOT NULL,
    telefonavel_id character(26) NOT NULL,
    telefonavel_type character varying(255) NOT NULL,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: telescope_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.telescope_entries (
    sequence bigint NOT NULL,
    uuid uuid NOT NULL,
    batch_id uuid NOT NULL,
    family_hash character varying(255),
    should_display_on_index boolean DEFAULT true NOT NULL,
    type character varying(20) NOT NULL,
    content text NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: telescope_entries_sequence_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.telescope_entries_sequence_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: telescope_entries_sequence_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.telescope_entries_sequence_seq OWNED BY public.telescope_entries.sequence;


--
-- Name: telescope_entries_tags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.telescope_entries_tags (
    entry_uuid uuid NOT NULL,
    tag character varying(255) NOT NULL
);


--
-- Name: telescope_monitoring; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.telescope_monitoring (
    tag character varying(255) NOT NULL
);


--
-- Name: unidades_consumidoras; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.unidades_consumidoras (
    id character(26) NOT NULL,
    usuario_id character(26) NOT NULL,
    concessionaria_id character(26) NOT NULL,
    endereco_id character(26),
    mesmo_titular boolean DEFAULT true NOT NULL,
    nome_titular_diferente character varying(255),
    apelido character varying(255),
    numero_cliente character varying(255),
    numero_unidade bigint NOT NULL,
    tipo character varying(255),
    gerador boolean DEFAULT false NOT NULL,
    geracao_prevista numeric(12,2),
    consumo_medio numeric(12,2),
    service boolean DEFAULT false NOT NULL,
    project boolean DEFAULT false NOT NULL,
    nexus_clube boolean DEFAULT false NOT NULL,
    nexus_cativo boolean DEFAULT false NOT NULL,
    proprietario boolean DEFAULT false NOT NULL,
    tensao_nominal integer,
    grupo character varying(1),
    ligacao character varying(255),
    irrigante boolean DEFAULT false NOT NULL,
    valor_beneficio_irrigante numeric(12,2),
    calibragem_percentual numeric(5,2),
    relacao_te numeric(8,4),
    classe character varying(255),
    subclasse character varying(255),
    tipo_conexao character varying(255),
    estrutura_tarifaria character varying(255),
    contrato character varying(255),
    vencimento_contrato date,
    demanda_geracao numeric(12,2),
    demanda_consumo numeric(12,2),
    desconto_fatura numeric(5,2),
    desconto_bandeira numeric(5,2),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    proposta_id character varying(36),
    distribuidora character varying(100),
    valor_fatura numeric(10,2),
    nome_usina character varying(200),
    potencia_cc numeric(10,2),
    fator_capacidade numeric(5,2),
    capacidade_calculada numeric(10,2),
    localizacao character varying(200),
    observacoes_ug text,
    ucs_atribuidas_detalhes json DEFAULT '[]'::json NOT NULL,
    deleted_by character varying(36),
    potencia_ca numeric(10,2),
    CONSTRAINT chk_fator_capacidade_range CHECK (((fator_capacidade IS NULL) OR ((fator_capacidade >= (0)::numeric) AND (fator_capacidade <= (100)::numeric)))),
    CONSTRAINT chk_potencia_cc_positive CHECK (((potencia_cc IS NULL) OR (potencia_cc > (0)::numeric))),
    CONSTRAINT chk_ug_fields_gerador CHECK (((gerador = false) OR ((gerador = true) AND (nome_usina IS NOT NULL) AND (potencia_cc IS NOT NULL) AND (fator_capacidade IS NOT NULL))))
);


--
-- Name: COLUMN unidades_consumidoras.proposta_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.proposta_id IS 'ID da proposta vinculada';


--
-- Name: COLUMN unidades_consumidoras.distribuidora; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.distribuidora IS 'Nome da distribuidora de energia';


--
-- Name: COLUMN unidades_consumidoras.valor_fatura; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.valor_fatura IS 'Valor médio da fatura mensal';


--
-- Name: COLUMN unidades_consumidoras.nome_usina; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.nome_usina IS 'Nome da usina geradora (quando is_ug=true)';


--
-- Name: COLUMN unidades_consumidoras.potencia_cc; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.potencia_cc IS 'Potência CC da usina em kWp';


--
-- Name: COLUMN unidades_consumidoras.fator_capacidade; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.fator_capacidade IS 'Fator de capacidade da usina em %';


--
-- Name: COLUMN unidades_consumidoras.capacidade_calculada; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.capacidade_calculada IS 'Capacidade mensal: 720h * potencia_cc * (fator_capacidade/100)';


--
-- Name: COLUMN unidades_consumidoras.localizacao; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.localizacao IS 'Localização da usina geradora';


--
-- Name: COLUMN unidades_consumidoras.observacoes_ug; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.observacoes_ug IS 'Observações específicas da UG';


--
-- Name: COLUMN unidades_consumidoras.potencia_ca; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unidades_consumidoras.potencia_ca IS 'Potência CA em kWp (para UGs)';


--
-- Name: usuarios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuarios (
    id character(26) NOT NULL,
    concessionaria_atual_id character(26),
    organizacao_atual_id character(26),
    nome character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    telefone character varying(20),
    instagram character varying(255),
    status character varying(255) DEFAULT 'Ativo'::character varying NOT NULL,
    cpf_cnpj character varying(255),
    cidade character varying(255),
    estado character varying(255),
    endereco character varying(255),
    cep character varying(255),
    deleted_by character(26),
    senha character varying(255),
    remember_token character varying(100),
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    role character varying(255) DEFAULT 'vendedor'::character varying NOT NULL,
    manager_id character varying(36),
    is_active boolean DEFAULT true NOT NULL,
    pix character varying(255),
    CONSTRAINT usuarios_role_check CHECK (((role)::text = ANY (ARRAY[('admin'::character varying)::text, ('consultor'::character varying)::text, ('gerente'::character varying)::text, ('vendedor'::character varying)::text])))
);


--
-- Name: COLUMN usuarios.email; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.usuarios.email IS 'Email usado para login (deve ser único)';


--
-- Name: COLUMN usuarios.role; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.usuarios.role IS 'Papel do usuário: admin, consultor, gerente, vendedor';


--
-- Name: COLUMN usuarios.manager_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.usuarios.manager_id IS 'ID do supervisor/gerente direto';


--
-- Name: COLUMN usuarios.is_active; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.usuarios.is_active IS 'Se o usuário está ativo no sistema';


--
-- Name: COLUMN usuarios.pix; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.usuarios.pix IS 'Chave PIX: CPF, CNPJ, email, telefone ou chave aleatória';


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: telescope_entries sequence; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telescope_entries ALTER COLUMN sequence SET DEFAULT nextval('public.telescope_entries_sequence_seq'::regclass);


--
-- Name: auditoria auditoria_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auditoria
    ADD CONSTRAINT auditoria_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: concessionaria_usuario concessionaria_usuario_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.concessionaria_usuario
    ADD CONSTRAINT concessionaria_usuario_pkey PRIMARY KEY (concessionaria_id, usuario_id);


--
-- Name: concessionarias concessionarias_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.concessionarias
    ADD CONSTRAINT concessionarias_pkey PRIMARY KEY (id);


--
-- Name: configuracoes configuracoes_chave_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.configuracoes
    ADD CONSTRAINT configuracoes_chave_unique UNIQUE (chave);


--
-- Name: configuracoes configuracoes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.configuracoes
    ADD CONSTRAINT configuracoes_pkey PRIMARY KEY (id);


--
-- Name: consorcios consorcios_cnpj_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consorcios
    ADD CONSTRAINT consorcios_cnpj_unique UNIQUE (cnpj);


--
-- Name: consorcios consorcios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consorcios
    ADD CONSTRAINT consorcios_pkey PRIMARY KEY (id);


--
-- Name: controle_clube controle_clube_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.controle_clube
    ADD CONSTRAINT controle_clube_pkey PRIMARY KEY (id);


--
-- Name: documentos documentos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documentos
    ADD CONSTRAINT documentos_pkey PRIMARY KEY (id);


--
-- Name: enderecos enderecos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.enderecos
    ADD CONSTRAINT enderecos_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: faturas faturas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.faturas
    ADD CONSTRAINT faturas_pkey PRIMARY KEY (id);


--
-- Name: feedbacks feedbacks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feedbacks
    ADD CONSTRAINT feedbacks_pkey PRIMARY KEY (id);


--
-- Name: historicos_faturamento historicos_faturamento_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.historicos_faturamento
    ADD CONSTRAINT historicos_faturamento_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- Name: notificacoes notificacoes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificacoes
    ADD CONSTRAINT notificacoes_pkey PRIMARY KEY (id);


--
-- Name: oportunidades oportunidades_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oportunidades
    ADD CONSTRAINT oportunidades_pkey PRIMARY KEY (id);


--
-- Name: organizacao_usuario organizacao_usuario_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizacao_usuario
    ADD CONSTRAINT organizacao_usuario_pkey PRIMARY KEY (organizacao_id, usuario_id);


--
-- Name: organizacoes organizacoes_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizacoes
    ADD CONSTRAINT organizacoes_email_unique UNIQUE (email);


--
-- Name: organizacoes organizacoes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizacoes
    ADD CONSTRAINT organizacoes_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: permissions permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: propostas propostas_numero_proposta_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.propostas
    ADD CONSTRAINT propostas_numero_proposta_unique UNIQUE (numero_proposta);


--
-- Name: propostas propostas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.propostas
    ADD CONSTRAINT propostas_pkey PRIMARY KEY (id);


--
-- Name: prospects prospects_cpf_cnpj_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prospects
    ADD CONSTRAINT prospects_cpf_cnpj_unique UNIQUE (cpf_cnpj);


--
-- Name: prospects prospects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prospects
    ADD CONSTRAINT prospects_pkey PRIMARY KEY (id);


--
-- Name: rateios rateios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rateios
    ADD CONSTRAINT rateios_pkey PRIMARY KEY (id);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: telefones telefones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telefones
    ADD CONSTRAINT telefones_pkey PRIMARY KEY (id);


--
-- Name: telescope_entries telescope_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telescope_entries
    ADD CONSTRAINT telescope_entries_pkey PRIMARY KEY (sequence);


--
-- Name: telescope_entries_tags telescope_entries_tags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telescope_entries_tags
    ADD CONSTRAINT telescope_entries_tags_pkey PRIMARY KEY (entry_uuid, tag);


--
-- Name: telescope_entries telescope_entries_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telescope_entries
    ADD CONSTRAINT telescope_entries_uuid_unique UNIQUE (uuid);


--
-- Name: telescope_monitoring telescope_monitoring_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telescope_monitoring
    ADD CONSTRAINT telescope_monitoring_pkey PRIMARY KEY (tag);


--
-- Name: unidades_consumidoras unidades_consumidoras_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidades_consumidoras
    ADD CONSTRAINT unidades_consumidoras_pkey PRIMARY KEY (id);


--
-- Name: controle_clube unique_proposta_uc; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.controle_clube
    ADD CONSTRAINT unique_proposta_uc UNIQUE (proposta_id, uc_id);


--
-- Name: usuarios usuarios_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_email_unique UNIQUE (email);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id);


--
-- Name: auditoria_acao_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auditoria_acao_index ON public.auditoria USING btree (acao);


--
-- Name: auditoria_data_acao_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auditoria_data_acao_index ON public.auditoria USING btree (data_acao);


--
-- Name: auditoria_entidade_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auditoria_entidade_id_index ON public.auditoria USING btree (entidade_id);


--
-- Name: auditoria_entidade_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auditoria_entidade_index ON public.auditoria USING btree (entidade);


--
-- Name: auditoria_usuario_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auditoria_usuario_id_index ON public.auditoria USING btree (usuario_id);


--
-- Name: controle_clube_status_troca_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX controle_clube_status_troca_index ON public.controle_clube USING btree (status_troca);


--
-- Name: documentos_organizacao_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documentos_organizacao_id_created_at_index ON public.documentos USING btree (organizacao_id, created_at);


--
-- Name: documentos_usuario_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX documentos_usuario_id_created_at_index ON public.documentos USING btree (usuario_id, created_at);


--
-- Name: idx_auditoria_acao_data; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auditoria_acao_data ON public.auditoria USING btree (acao, data_acao DESC);


--
-- Name: idx_auditoria_entidade_data; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auditoria_entidade_data ON public.auditoria USING btree (entidade, data_acao DESC);


--
-- Name: idx_auditoria_entidade_id_data; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auditoria_entidade_id_data ON public.auditoria USING btree (entidade, entidade_id, data_acao DESC);


--
-- Name: idx_auditoria_usuario_data; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auditoria_usuario_data ON public.auditoria USING btree (usuario_id, data_acao DESC);


--
-- Name: idx_config_chave; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_config_chave ON public.configuracoes USING btree (chave);


--
-- Name: idx_config_grupo; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_config_grupo ON public.configuracoes USING btree (grupo);


--
-- Name: idx_config_updated_by; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_config_updated_by ON public.configuracoes USING btree (updated_by);


--
-- Name: idx_controle_data_entrada; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_controle_data_entrada ON public.controle_clube USING btree (data_entrada_controle);


--
-- Name: idx_controle_proposta; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_controle_proposta ON public.controle_clube USING btree (proposta_id);


--
-- Name: idx_controle_uc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_controle_uc ON public.controle_clube USING btree (uc_id);


--
-- Name: idx_controle_ug; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_controle_ug ON public.controle_clube USING btree (ug_id);


--
-- Name: idx_documents_proposta_uc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_proposta_uc ON public.documents USING btree (proposta_id, numero_uc);


--
-- Name: idx_propostas_consultor_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_propostas_consultor_id ON public.propostas USING btree (consultor_id);


--
-- Name: idx_propostas_data; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_propostas_data ON public.propostas USING btree (data_proposta);


--
-- Name: idx_propostas_data_proposta; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_propostas_data_proposta ON public.propostas USING btree (data_proposta);


--
-- Name: idx_propostas_numero; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_propostas_numero ON public.propostas USING btree (numero_proposta);


--
-- Name: idx_propostas_usuario; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_propostas_usuario ON public.propostas USING btree (usuario_id);


--
-- Name: idx_propostas_usuario_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_propostas_usuario_id ON public.propostas USING btree (usuario_id);


--
-- Name: idx_uc_deleted; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_uc_deleted ON public.unidades_consumidoras USING btree (deleted_at, deleted_by);


--
-- Name: idx_uc_distribuidora; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_uc_distribuidora ON public.unidades_consumidoras USING btree (distribuidora);


--
-- Name: idx_uc_proposta; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_uc_proposta ON public.unidades_consumidoras USING btree (proposta_id);


--
-- Name: idx_usuarios_email; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_usuarios_email ON public.usuarios USING btree (email);


--
-- Name: idx_usuarios_manager; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_usuarios_manager ON public.usuarios USING btree (manager_id);


--
-- Name: idx_usuarios_pix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_usuarios_pix ON public.usuarios USING btree (pix);


--
-- Name: idx_usuarios_role; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_usuarios_role ON public.usuarios USING btree (role);


--
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON public.model_has_permissions USING btree (model_id, model_type);


--
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_roles_model_id_model_type_index ON public.model_has_roles USING btree (model_id, model_type);


--
-- Name: propostas_inflacao_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX propostas_inflacao_index ON public.propostas USING btree (inflacao);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: telescope_entries_batch_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telescope_entries_batch_id_index ON public.telescope_entries USING btree (batch_id);


--
-- Name: telescope_entries_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telescope_entries_created_at_index ON public.telescope_entries USING btree (created_at);


--
-- Name: telescope_entries_family_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telescope_entries_family_hash_index ON public.telescope_entries USING btree (family_hash);


--
-- Name: telescope_entries_tags_tag_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telescope_entries_tags_tag_index ON public.telescope_entries_tags USING btree (tag);


--
-- Name: telescope_entries_type_should_display_on_index_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telescope_entries_type_should_display_on_index_index ON public.telescope_entries USING btree (type, should_display_on_index);


--
-- Name: concessionaria_usuario concessionaria_usuario_concessionaria_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.concessionaria_usuario
    ADD CONSTRAINT concessionaria_usuario_concessionaria_id_foreign FOREIGN KEY (concessionaria_id) REFERENCES public.concessionarias(id) ON DELETE CASCADE;


--
-- Name: concessionaria_usuario concessionaria_usuario_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.concessionaria_usuario
    ADD CONSTRAINT concessionaria_usuario_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;


--
-- Name: consorcios consorcios_organizacao_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consorcios
    ADD CONSTRAINT consorcios_organizacao_id_foreign FOREIGN KEY (organizacao_id) REFERENCES public.organizacoes(id);


--
-- Name: documentos documentos_concessionaria_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documentos
    ADD CONSTRAINT documentos_concessionaria_id_foreign FOREIGN KEY (concessionaria_id) REFERENCES public.concessionarias(id);


--
-- Name: documentos documentos_organizacao_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documentos
    ADD CONSTRAINT documentos_organizacao_id_foreign FOREIGN KEY (organizacao_id) REFERENCES public.organizacoes(id);


--
-- Name: documentos documentos_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documentos
    ADD CONSTRAINT documentos_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: faturas faturas_concessionaria_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.faturas
    ADD CONSTRAINT faturas_concessionaria_id_foreign FOREIGN KEY (concessionaria_id) REFERENCES public.concessionarias(id);


--
-- Name: faturas faturas_unidade_consumidora_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.faturas
    ADD CONSTRAINT faturas_unidade_consumidora_id_foreign FOREIGN KEY (unidade_consumidora_id) REFERENCES public.unidades_consumidoras(id);


--
-- Name: feedbacks feedbacks_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feedbacks
    ADD CONSTRAINT feedbacks_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;


--
-- Name: configuracoes fk_config_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.configuracoes
    ADD CONSTRAINT fk_config_updated_by FOREIGN KEY (updated_by) REFERENCES public.usuarios(id) ON DELETE SET NULL;


--
-- Name: controle_clube fk_controle_proposta; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.controle_clube
    ADD CONSTRAINT fk_controle_proposta FOREIGN KEY (proposta_id) REFERENCES public.propostas(id) ON DELETE CASCADE;


--
-- Name: controle_clube fk_controle_uc; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.controle_clube
    ADD CONSTRAINT fk_controle_uc FOREIGN KEY (uc_id) REFERENCES public.unidades_consumidoras(id) ON DELETE CASCADE;


--
-- Name: controle_clube fk_controle_ug; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.controle_clube
    ADD CONSTRAINT fk_controle_ug FOREIGN KEY (ug_id) REFERENCES public.unidades_consumidoras(id) ON DELETE SET NULL;


--
-- Name: propostas fk_propostas_consultor; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.propostas
    ADD CONSTRAINT fk_propostas_consultor FOREIGN KEY (consultor_id) REFERENCES public.usuarios(id) ON DELETE SET NULL;


--
-- Name: propostas fk_propostas_usuario; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.propostas
    ADD CONSTRAINT fk_propostas_usuario FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE RESTRICT;


--
-- Name: unidades_consumidoras fk_uc_deleted_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidades_consumidoras
    ADD CONSTRAINT fk_uc_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.usuarios(id) ON DELETE SET NULL;


--
-- Name: unidades_consumidoras fk_uc_proposta; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidades_consumidoras
    ADD CONSTRAINT fk_uc_proposta FOREIGN KEY (proposta_id) REFERENCES public.propostas(id) ON DELETE SET NULL;


--
-- Name: usuarios fk_usuarios_manager; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT fk_usuarios_manager FOREIGN KEY (manager_id) REFERENCES public.usuarios(id) ON DELETE SET NULL;


--
-- Name: historicos_faturamento historicos_faturamento_concessionaria_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.historicos_faturamento
    ADD CONSTRAINT historicos_faturamento_concessionaria_id_foreign FOREIGN KEY (concessionaria_id) REFERENCES public.concessionarias(id);


--
-- Name: historicos_faturamento historicos_faturamento_unidade_consumidora_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.historicos_faturamento
    ADD CONSTRAINT historicos_faturamento_unidade_consumidora_id_foreign FOREIGN KEY (unidade_consumidora_id) REFERENCES public.unidades_consumidoras(id);


--
-- Name: model_has_permissions model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: notificacoes notificacoes_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificacoes
    ADD CONSTRAINT notificacoes_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;


--
-- Name: oportunidades oportunidades_admin_responsavel_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oportunidades
    ADD CONSTRAINT oportunidades_admin_responsavel_id_foreign FOREIGN KEY (admin_responsavel_id) REFERENCES public.usuarios(id);


--
-- Name: oportunidades oportunidades_organizacao_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oportunidades
    ADD CONSTRAINT oportunidades_organizacao_id_foreign FOREIGN KEY (organizacao_id) REFERENCES public.organizacoes(id);


--
-- Name: oportunidades oportunidades_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oportunidades
    ADD CONSTRAINT oportunidades_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: organizacao_usuario organizacao_usuario_organizacao_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizacao_usuario
    ADD CONSTRAINT organizacao_usuario_organizacao_id_foreign FOREIGN KEY (organizacao_id) REFERENCES public.organizacoes(id) ON DELETE CASCADE;


--
-- Name: organizacao_usuario organizacao_usuario_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizacao_usuario
    ADD CONSTRAINT organizacao_usuario_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;


--
-- Name: organizacoes organizacoes_deleted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizacoes
    ADD CONSTRAINT organizacoes_deleted_by_foreign FOREIGN KEY (deleted_by) REFERENCES public.usuarios(id) ON DELETE SET NULL;


--
-- Name: prospects prospects_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prospects
    ADD CONSTRAINT prospects_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: rateios rateios_consorcio_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rateios
    ADD CONSTRAINT rateios_consorcio_id_foreign FOREIGN KEY (consorcio_id) REFERENCES public.consorcios(id) ON DELETE CASCADE;


--
-- Name: rateios rateios_organizacao_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rateios
    ADD CONSTRAINT rateios_organizacao_id_foreign FOREIGN KEY (organizacao_id) REFERENCES public.organizacoes(id) ON DELETE CASCADE;


--
-- Name: rateios rateios_unidade_beneficiaria_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rateios
    ADD CONSTRAINT rateios_unidade_beneficiaria_id_foreign FOREIGN KEY (unidade_beneficiaria_id) REFERENCES public.unidades_consumidoras(id) ON DELETE CASCADE;


--
-- Name: rateios rateios_unidade_geradora_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rateios
    ADD CONSTRAINT rateios_unidade_geradora_id_foreign FOREIGN KEY (unidade_geradora_id) REFERENCES public.unidades_consumidoras(id) ON DELETE CASCADE;


--
-- Name: rateios rateios_usuario_admin_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rateios
    ADD CONSTRAINT rateios_usuario_admin_id_foreign FOREIGN KEY (usuario_admin_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: telescope_entries_tags telescope_entries_tags_entry_uuid_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telescope_entries_tags
    ADD CONSTRAINT telescope_entries_tags_entry_uuid_foreign FOREIGN KEY (entry_uuid) REFERENCES public.telescope_entries(uuid) ON DELETE CASCADE;


--
-- Name: unidades_consumidoras unidades_consumidoras_concessionaria_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidades_consumidoras
    ADD CONSTRAINT unidades_consumidoras_concessionaria_id_foreign FOREIGN KEY (concessionaria_id) REFERENCES public.concessionarias(id);


--
-- Name: unidades_consumidoras unidades_consumidoras_endereco_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidades_consumidoras
    ADD CONSTRAINT unidades_consumidoras_endereco_id_foreign FOREIGN KEY (endereco_id) REFERENCES public.enderecos(id);


--
-- Name: unidades_consumidoras unidades_consumidoras_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidades_consumidoras
    ADD CONSTRAINT unidades_consumidoras_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: usuarios usuarios_concessionaria_atual_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_concessionaria_atual_id_foreign FOREIGN KEY (concessionaria_atual_id) REFERENCES public.concessionarias(id);


--
-- Name: usuarios usuarios_organizacao_atual_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_organizacao_atual_id_foreign FOREIGN KEY (organizacao_atual_id) REFERENCES public.organizacoes(id);


--
-- PostgreSQL database dump complete
--

\unrestrict 8ykVakb5kiROgKFRHbbjU5WzneoncJB9MxJEsOTtqORGwmXnBZhjRClqKJtbnAC

--
-- PostgreSQL database dump
--

\restrict HB0UhuizyfQpPQHW044yVDaPDcU1eebK6iGsEYGYA4ohaxKxuYxcLz1tzAixrTZ

-- Dumped from database version 16.10 (Ubuntu 16.10-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.10 (Ubuntu 16.10-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_enderecos_table	1
2	0001_01_01_000001_create_concessionarias_table	1
3	0001_01_02_000001_create_organizacoes_table	1
4	0001_01_02_000002_create_usuarios_table	1
5	0001_01_02_000003_create_organizacao_usuario_table	1
6	2024_06_06_121058_create_personal_access_tokens_table	1
7	2024_06_06_195513_create_permission_tables	1
8	2024_06_06_205707_alter_model_id_in_model_has_roles_and_permissions	1
9	2024_06_11_000728_create_telescope_entries_table	1
10	2024_06_23_215528_create_telefones_table	1
11	2025_01_02_160828_create_failed_jobs_table	1
12	2025_01_22_140855_create_concessionaria_usuario_table	1
13	2025_01_22_140856_create_unidades_consumidoras_table	1
14	2025_01_22_143251_create_faturas_table	1
15	2025_01_22_143827_create_consorcios_table	1
16	2025_01_22_143942_create_rateios_table	1
17	2025_01_22_194449_create_historicos_faturamento_table	1
18	2025_01_28_211756_create_oportunidades_table	1
19	2025_02_18_210221_create_prospects_table	1
20	2025_02_19_160854_rename_uc_to_ucs_in_prospects	1
21	2025_03_11_161602_create_feedbacks_table	1
22	2025_03_11_162840_add_deleted_by_to_organizacoes_table	1
23	2025_03_11_201347_create_notificacoes_table	1
24	2025_06_03_161428_create_documentos_table	1
25	0001_01_01_000000_create_users_table	1
26	0001_01_01_000001_create_cache_table	1
27	0001_01_01_000002_create_jobs_table	1
28	2025_08_05_135005_create_permission_tables	1
29	2025_08_05_120001_add_hierarchy_to_usuarios_table	2
30	2025_08_05_120002_expand_unidades_consumidoras_for_ugs	2
31	2025_08_05_120003_create_propostas_table	2
32	2025_08_05_120004_create_controle_clube_table	2
33	2025_08_05_120005_create_configuracoes_table	2
34	2025_08_05_120006_add_foreign_keys_and_constraints	2
35	2025_08_05_120007_add_aupus_permissions	2
36	2025_08_05_142244_create_cache_table	2
37	2025_08_12_120959_create_propostas_table	3
38	2025_08_12_135905_add_all_frontend_fields_to_propostas_table	4
39	2025_08_12_165425_fix_unidades_consumidoras_column_type	5
40	2025_08_12_172900_delete_columns_propostas	6
41	2025_08_12_192523_delete_columns_proposta_2	7
42	2025_08_13_144438_rename_economia_bandeira_to_desconto_fields	8
43	2025_08_14_123001_update_propostas_status_constraint	9
44	2025_08_14_133422_remove_status_from_propostas_table	10
45	2025_08_14_172725_add_documentacao_to_propostas_table	11
47	2025_08_15_173845_remove_tipo_ligacao_column	12
49	2025_08_18_125207_remove_calibragem_from_controle_and_add_to_config	13
50	2025_08_18_140332_remove_is_ug_from_unidades_consumidoras_table	13
51	2025_08_18_141556_update_trigger_calculate_capacidade_ug	14
52	2025_08_20_195940_add_status_troca_and_ucs_detalhes_fields	15
53	2025_08_21_174206_fix_constraints_to_use_gerador	16
54	2025_08_21_201403_add_deleted_by_to_unidades_consumidoras	17
55	2025_08_25_173208_add_columns_tarifa_and_inflacao_proposta	18
56	2025_08_27_013659_add_pix_to_usuarios_table	19
57	2025_08_27_134217_insert_novos_consultores	20
58	2025_08_28_204121_update_propostas_and_unidades_consumidoras_fields	21
59	2025_09_04_151031_change_status_troca_aguardando_to_esteira_finalizado_to_associado	22
60	2025_09_08_001802_migrate_consultor_to_consultor_id_and_remove_consultor_field	23
61	2025_09_08_135558_create_auditoria_table	24
62	2025_09_08_172630_add_faturas_ucs_support_to_propostas_table	24
63	2025_09_10_124224_add_calibragem_individual_to_controle_clube	25
64	2025_09_10_201322_add_cpf_and_logadouro_fields_to_propostas	26
65	2025_09_11_012017_remove_logadouro_uc_from_propostas_table	27
66	2025_09_11_113915_create_documents_table	28
67	2025_09_11_124735_create_documents_table	29
68	2025_09_15_103455_create_documents_table	30
69	2025_09_15_103706_add_cancelled_fields_to_documents_table	31
72	2025_09_16_151944_add_manual_upload_fields_to_documents_table	32
74	2025_09_16_151950_create_documents_for_closed_propostas	33
78	2025_09_16_151950_create_documents_for_closed_propostas_1	34
79	2025_09_17_094904_add_desconto_fields_to_controle_clube	35
80	2025_09_17_154556_add_numero_uc_to_documents_table	36
81	2025_09_19_115648_allow_null_desconto_columns_controle_clube_table	37
83	2025_09_19_145417_populate_spatie_roles_and_permissions	38
84	2025_09_22_150931_add_documentacao_to_controle_clube_table	39
85	2025_09_23_135145_update_usuarios_nome_capitalization	40
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 85, true);


--
-- PostgreSQL database dump complete
--

\unrestrict HB0UhuizyfQpPQHW044yVDaPDcU1eebK6iGsEYGYA4ohaxKxuYxcLz1tzAixrTZ

