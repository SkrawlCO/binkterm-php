import http from 'node:http';import {spawn} from 'node:child_process';import{readFile}from'node:fs/promises';import path from'node:path';
const root='/root/binktermphp/app/shared/wordwright/web/dist';
http.createServer(async(req,res)=>{
 if(req.url==='/state'){
  let body='';for await(const b of req){body+=b;if(body.length>110000){res.writeHead(413).end();return;}}
  const child=spawn('docker',['exec','-i','binkterm-app','php','/tmp/ww-proxy.php']);let output='';child.stdout.on('data',b=>output+=b);child.stderr.resume();
  child.stdin.end(JSON.stringify({headers:req.headers,body}));child.on('close',()=>{try{const r=JSON.parse(output);res.writeHead(r.status,{'Content-Type':'application/json','Cache-Control':'no-store'}).end(r.body);}catch{res.writeHead(502).end();}});return;
 }
 try{const pathname=new URL(req.url,'http://localhost').pathname;const file=path.resolve(root,'.'+(pathname==='/'?'/index.html':pathname));if(!file.startsWith(root+'/'))throw Error();const data=await readFile(file);res.writeHead(200,{'Content-Type':file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'text/html'}).end(data);}catch{res.writeHead(404).end();}
}).listen(43193,'127.0.0.1',()=>console.log('Loopback review server 43193'));
