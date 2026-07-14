CREATE TABLE IF NOT EXISTS  public.locations (
    name text,
    formid bigint,
    worldspace text,
    tags text,
    is_interior int,
    vanilla_location boolean,
    coords point,
    refs text,
    cleared boolean,
    updated_at timestamp
);


ALTER TABLE public.locations OWNER TO dwemer;

--
-- Name: TABLE locations; Type: COMMENT; Schema: public; Owner: dwemer
--

COMMENT ON TABLE public.locations IS 'locations sent from plugin';
