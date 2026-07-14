CREATE SEQUENCE public.moods_issued_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.moods_issued_rowid_seq OWNER TO dwemer;

CREATE TABLE public.moods_issued (
    sess character varying(1024),
    speaker text,
    mood text,
    listener text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    ts bigint,
    rowid bigint DEFAULT nextval('public.moods_issued_rowid_seq'::regclass) NOT NULL
);


ALTER TABLE public.moods_issued OWNER TO dwemer;

ALTER SEQUENCE public.moods_issued_rowid_seq OWNED BY public.moods_issued.rowid;

--
-- Name: moods_issued moods_issued_pkey; Type: CONSTRAINT; Schema: public; Owner: dwemer
--

ALTER TABLE ONLY public.moods_issued
    ADD CONSTRAINT moods_issued_pkey PRIMARY KEY (rowid);


--
-- PostgreSQL database dump complete
--
