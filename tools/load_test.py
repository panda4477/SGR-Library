#!/usr/bin/env python3
"""Local load test with 120 distinct authenticated PHP sessions and runtime observations."""
import argparse
import json
import math
import os
import statistics
import threading
import time
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import bcrypt

from qa_support import Client, connect, expect, test_config, unique_names


def processes():
    fpm_pid = int(os.environ.get("SGR_QA_FPM_PID", "0"))
    db_pid = int(os.environ.get("SGR_QA_DB_PID", "0"))
    result = {}
    for path in Path("/proc").glob("[0-9]*/stat"):
        try:
            fields = path.read_text().split(") ", 1)
            name = fields[0].split(" (",1)[1]
            family = "php_fpm" if "php-fpm" in name else ("mariadb" if name in ("mariadbd","mysqld") else None)
            if not family:
                continue
            vals = fields[1].split()
            pid = int(path.parent.name)
            if family == "mariadb" and db_pid and pid != db_pid:
                continue
            if family == "php_fpm" and fpm_pid and pid != fpm_pid and int(vals[1]) != fpm_pid:
                continue
            result[pid] = (family,int(vals[11])+int(vals[12]),int(vals[21])*os.sysconf("SC_PAGE_SIZE")/1048576)
        except (OSError, IndexError, ValueError):
            continue
    return result


def database_threads(conn):
    with conn.cursor() as cur:
        cur.execute("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running')")
        return {k:int(v) for k,v in cur.fetchall()}


def benchmark(clients, rounds):
    n = len(clients)
    start_gate = threading.Barrier(n+1, timeout=35)
    stop = threading.Event()
    peak_rss, max_count = Counter(), Counter()
    max_threads = Counter()
    monitor_errors = []
    mem_available = []
    observed = {}
    before = processes()

    def sample():
        conn = None
        try:
            conn=connect()
            while True:
                group_rss, group_count = Counter(),Counter()
                snapshot=processes()
                for pid, (family,ticks,rss) in snapshot.items():
                    group_rss[family]+=rss
                    group_count[family]+=1
                    observed[pid]=(family,ticks)
                for group in group_rss:
                    peak_rss[group]=max(peak_rss[group],group_rss[group])
                    max_count[group]=max(max_count[group],group_count[group])
                for field, number in database_threads(conn).items():
                    max_threads[field]=max(max_threads[field],number)
                for line in Path("/proc/meminfo").read_text().splitlines():
                    if line.startswith("MemAvailable:"):
                        mem_available.append(int(line.split()[1])/1024)
                        break
                if stop.wait(.05):
                    break
        except Exception as exc:
            monitor_errors.append(str(exc))
        finally:
            if conn: conn.close()

    paths=("/dashboard.php","/activities.php","/assignments.php","/api/student/scores","/attendance.php","/profile.php")
    def visit(client):
        samples=[]; statuses=Counter()
        start_gate.wait()
        for route in paths * rounds:
            started=time.perf_counter()
            try:
                response=client.request(route)
                status=str(response.status)
            except (OSError, TimeoutError):
                status="connection_error"
            samples.append(1000*(time.perf_counter()-started));statuses[status]+=1
        return samples,statuses

    latencies=[]; codes=Counter()
    with ThreadPoolExecutor(max_workers=n) as executor:
        jobs=[executor.submit(visit,c) for c in clients]
        sampler=threading.Thread(target=sample);sampler.start()
        start=time.perf_counter();start_gate.wait()
        try:
            for job in jobs:
                values,counts=job.result()
                latencies.extend(values);codes.update(counts)
        finally:
            seconds=time.perf_counter()-start
            stop.set();sampler.join()
    if monitor_errors:
        raise RuntimeError("Resource monitor failed: "+"; ".join(monitor_errors))
    for pid,(family,ticks,_) in processes().items():
        observed[pid]=(family,ticks)
    cpu=Counter()
    for pid,(family,ticks) in observed.items():
        baseline=before[pid][1] if pid in before else 0
        cpu[family]+=max(0,ticks-baseline)/os.sysconf("SC_CLK_TCK")
    latencies.sort()
    return {
        "users":n,"requests_per_session":len(paths)*rounds,"requests":len(latencies),"http_status":dict(codes),"errors":len(latencies)-codes["200"],
        "duration_s":round(seconds,3),"requests_s":round(len(latencies)/seconds,1),
        "median_ms":round(statistics.median(latencies),1),"p95_ms":round(latencies[math.ceil(.95*len(latencies))-1],1),
        "p99_ms":round(latencies[math.ceil(.99*len(latencies))-1],1),
        "services":{g:{"peak_processes":max_count[g],"peak_sum_rss_mib":round(peak_rss[g],1),
                       "cpu_core_percent":round(100*cpu[g]/seconds,1)} for g in ("php_fpm","mariadb")},
        "database_peak_threads":dict(max_threads),"lowest_host_memavailable_mib":round(min(mem_available),1) if mem_available else None}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output",type=Path,help="Write JSON results")
    parser.add_argument("--rounds",type=int,default=10,help="Repetitions of the six authenticated routes per user")
    args=parser.parse_args()
    if not 1<=args.rounds<=100: parser.error("rounds must be between 1 and 100")
    test_config()
    tag,password=unique_names()
    usernames=[tag+"l"+str(i) for i in range(120)]
    hashed=bcrypt.hashpw(password.encode(),bcrypt.gensalt(rounds=12)).decode()
    conn=connect()
    with conn.cursor() as cur:
        cur.executemany("INSERT INTO users(role,username,password_hash,first_name,last_name,student_code,level_name) VALUES('student',%s,%s,'ผู้เรียน','ทดสอบโหลด',%s,'มัธยมศึกษาตอนต้น')",
                        [(name,hashed,name) for name in usernames])
    conn.close()

    def login(name):
        c=Client();c.login(name,password)
        response=expect(c.request("/api/me"),200,"load-test login")
        if response.json()["username"] != name: raise RuntimeError("Mixed sessions")
        return c
    print("Preparing 120 unique sessions through /login.php",flush=True)
    with ThreadPoolExecutor(max_workers=4) as executor:
        clients=list(executor.map(login,usernames))
    results=[]
    for count in (20,50,120):
        result=benchmark(clients[:count],args.rounds); results.append(result)
        print(json.dumps(result,ensure_ascii=False),flush=True)
    if args.output: args.output.write_text(json.dumps(results,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    if any(r["errors"] for r in results):
        raise SystemExit("HTTP errors detected")


if __name__=="__main__":
    main()
